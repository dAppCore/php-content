<?php

declare(strict_types=1);

namespace Core\Mod\Content\Services;

use Core\Mod\Agentic\Services\AgenticResponse;
use Core\Mod\Agentic\Services\ClaudeService;
use Core\Mod\Agentic\Services\GeminiService;
use Core\Mod\Content\Models\AIUsage;
use Core\Mod\Content\Models\ContentBrief;
use RuntimeException;

/**
 * AIGatewayService
 *
 * Orchestrates the two-stage AI content pipeline:
 * 1. Gemini (fast, cheap) for initial draft generation
 * 2. Claude (quality) for refinement and brand voice alignment
 *
 * Also handles usage tracking and prompt template management.
 *
 * Note: Config is read fresh on each getGemini()/getClaude() call to support
 * runtime config changes (e.g., different keys per workspace in future).
 */
class AIGatewayService
{
    protected ?GeminiService $gemini = null;

    protected ?ClaudeService $claude = null;

    /**
     * Optional override keys - if null, config() is used fresh on each call.
     */
    protected ?string $geminiApiKeyOverride = null;

    protected ?string $claudeApiKeyOverride = null;

    protected ?string $geminiModelOverride = null;

    protected ?string $claudeModelOverride = null;

    /**
     * Create a new AIGatewayService instance.
     *
     * All parameters are optional overrides. When null, config() is read
     * fresh on each service instantiation, allowing runtime config changes.
     */
    public function __construct(
        ?string $geminiApiKey = null,
        ?string $claudeApiKey = null,
        ?string $geminiModel = null,
        ?string $claudeModel = null,
    ) {
        $this->geminiApiKeyOverride = $geminiApiKey;
        $this->claudeApiKeyOverride = $claudeApiKey;
        $this->geminiModelOverride = $geminiModel;
        $this->claudeModelOverride = $claudeModel;
    }

    /**
     * Generate a draft using Gemini.
     */
    public function generateDraft(
        ContentBrief $brief,
        ?array $additionalContext = null
    ): AgenticResponse {
        $gemini = $this->getGemini();

        $systemPrompt = $this->getDraftSystemPrompt($brief);
        $userPrompt = $this->buildDraftPrompt($brief, $additionalContext);

        $response = $gemini->generate($systemPrompt, $userPrompt, [
            'max_tokens' => max(4096, $brief->target_word_count * 2),
            'temperature' => 0.7,
        ]);

        // Track usage
        AIUsage::fromResponse(
            $response,
            AIUsage::PURPOSE_DRAFT,
            $brief->workspace_id,
            $brief->id
        );

        return $response;
    }

    /**
     * Refine draft content using Claude.
     */
    public function refineDraft(
        ContentBrief $brief,
        string $draftContent,
        ?array $additionalContext = null
    ): AgenticResponse {
        $claude = $this->getClaude();

        $systemPrompt = $this->getRefineSystemPrompt();
        $userPrompt = $this->buildRefinePrompt($brief, $draftContent, $additionalContext);

        $response = $claude->generate($systemPrompt, $userPrompt, [
            'max_tokens' => max(4096, $brief->target_word_count * 2),
            'temperature' => 0.5,
        ]);

        // Track usage
        AIUsage::fromResponse(
            $response,
            AIUsage::PURPOSE_REFINE,
            $brief->workspace_id,
            $brief->id
        );

        return $response;
    }

    /**
     * Generate social media posts from content.
     */
    public function generateSocialPosts(
        string $sourceContent,
        array $platforms,
        ?int $workspaceId = null,
        ?int $briefId = null
    ): AgenticResponse {
        $claude = $this->getClaude();

        $systemPrompt = $this->getSocialSystemPrompt();
        $userPrompt = $this->buildSocialPrompt($sourceContent, $platforms);

        $response = $claude->generate($systemPrompt, $userPrompt, [
            'max_tokens' => 2048,
            'temperature' => 0.7,
        ]);

        AIUsage::fromResponse(
            $response,
            AIUsage::PURPOSE_SOCIAL,
            $workspaceId,
            $briefId
        );

        return $response;
    }

    /**
     * Run the full two-stage pipeline: Gemini draft → Claude refine.
     */
    public function generateAndRefine(
        ContentBrief $brief,
        ?array $additionalContext = null
    ): array {
        $brief->markGenerating();

        // Stage 1: Generate draft with Gemini
        $draftResponse = $this->generateDraft($brief, $additionalContext);
        $brief->markDraftComplete($draftResponse->content, [
            'draft' => [
                'model' => $draftResponse->model,
                'tokens' => $draftResponse->totalTokens(),
                'cost' => $draftResponse->estimateCost(),
                'duration_ms' => $draftResponse->durationMs,
            ],
        ]);

        // Stage 2: Refine with Claude
        $refineResponse = $this->refineDraft($brief, $draftResponse->content, $additionalContext);
        $brief->markRefined($refineResponse->content, [
            'refine' => [
                'model' => $refineResponse->model,
                'tokens' => $refineResponse->totalTokens(),
                'cost' => $refineResponse->estimateCost(),
                'duration_ms' => $refineResponse->durationMs,
            ],
        ]);

        return [
            'draft' => $draftResponse,
            'refined' => $refineResponse,
            'brief' => $brief->fresh(),
        ];
    }

    /**
     * Generate content directly with Claude (skip Gemini for critical content).
     */
    public function generateDirect(
        ContentBrief $brief,
        ?array $additionalContext = null
    ): AgenticResponse {
        $claude = $this->getClaude();

        $brief->markGenerating();

        $systemPrompt = $this->getDraftSystemPrompt($brief);
        $userPrompt = $this->buildDraftPrompt($brief, $additionalContext);

        $response = $claude->generate($systemPrompt, $userPrompt, [
            'max_tokens' => max(4096, $brief->target_word_count * 2),
            'temperature' => 0.6,
        ]);

        AIUsage::fromResponse(
            $response,
            AIUsage::PURPOSE_DRAFT,
            $brief->workspace_id,
            $brief->id
        );

        $brief->markRefined($response->content, [
            'direct' => [
                'model' => $response->model,
                'tokens' => $response->totalTokens(),
                'cost' => $response->estimateCost(),
                'duration_ms' => $response->durationMs,
            ],
        ]);

        return $response;
    }

    /**
     * Get the draft system prompt based on content type.
     */
    protected function getDraftSystemPrompt(ContentBrief $brief): string
    {
        $basePrompt = <<<'PROMPT'
You are a content strategist for Host UK, a British SaaS company providing hosting, analytics, and digital marketing tools.

Write high-quality content that:
- Uses UK English spelling (colour, organisation, centre)
- Has a professional but approachable tone
- Is knowledgeable but not condescending
- Avoids buzzwords, hyperbole, and corporate speak
- Uses Oxford commas
- Never uses exclamation marks

Output format: Markdown with YAML frontmatter.
PROMPT;

        $typeSpecific = match ($brief->content_type) {
            'help_article' => $this->getHelpArticlePrompt(),
            'blog_post' => $this->getBlogPostPrompt(),
            'landing_page' => $this->getLandingPagePrompt(),
            'social_post' => $this->getSocialPostPrompt(),
            default => '',
        };

        return $basePrompt."\n\n".$typeSpecific;
    }

    /**
     * Build the user prompt for draft generation.
     */
    protected function buildDraftPrompt(ContentBrief $brief, ?array $additionalContext): string
    {
        $context = $brief->buildPromptContext();

        if ($additionalContext) {
            $context = array_merge($context, $additionalContext);
        }

        $prompt = "Write a {$brief->content_type} about: {$brief->title}\n\n";

        if ($brief->description) {
            $prompt .= "Description: {$brief->description}\n\n";
        }

        if ($brief->keywords) {
            $prompt .= 'Keywords to include: '.implode(', ', $brief->keywords)."\n\n";
        }

        if ($brief->category) {
            $prompt .= "Category: {$brief->category}\n";
        }

        if ($brief->difficulty) {
            $prompt .= "Difficulty level: {$brief->difficulty}\n";
        }

        $prompt .= "Target word count: {$brief->target_word_count}\n\n";

        if ($brief->prompt_variables) {
            $prompt .= "Additional context:\n";
            foreach ($brief->prompt_variables as $key => $value) {
                if (is_string($value)) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
        }

        return $prompt;
    }

    /**
     * Get the refinement system prompt.
     */
    protected function getRefineSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the ghost writer and editor for Host UK. Your role is to transform draft content into polished, publication-ready material that sounds like it was written by our best human writer.

## Brand Voice Guidelines

**Personality:**
- Knowledgeable but not condescending
- Helpful and practical
- Quietly confident
- Occasionally witty (subtle, not forced)
- British sensibility (understated, dry humour acceptable)

**Writing style:**
- Clear, direct sentences
- Active voice preferred
- Contractions are fine (we're, you'll, it's)
- UK English spelling always
- No buzzwords or corporate speak
- No exclamation marks (almost never)
- Numbers under 10 spelled out
- Oxford comma: yes

**What to avoid:**
- "Leverage", "synergy", "cutting-edge"
- "We're excited to announce"
- Hyperbole ("revolutionary", "game-changing")
- Passive aggressive tones
- Overpromising

Transform the content by:
1. Voice alignment - Make it sound like Host UK
2. Flow improvement - Smooth transitions, better rhythm
3. Clarity enhancement - Simplify without dumbing down
4. Engagement hooks - Stronger opening, better section leads
5. CTA optimisation - Natural, compelling calls to action
6. UK localisation - Spelling, references, cultural fit

Preserve:
- All factual information
- SEO keywords and structure
- Technical accuracy
- Section organisation

Output the refined version with the same frontmatter structure.
PROMPT;
    }

    /**
     * Build the refine prompt.
     */
    protected function buildRefinePrompt(ContentBrief $brief, string $draftContent, ?array $additionalContext): string
    {
        $prompt = "Refine this {$brief->content_type} for Host UK.\n\n";

        if ($brief->service) {
            $prompt .= "Service: {$brief->service}\n";
        }

        if ($brief->difficulty) {
            $prompt .= "Target audience level: {$brief->difficulty}\n";
        }

        if ($additionalContext) {
            $prompt .= "\nAdditional guidance:\n";
            foreach ($additionalContext as $key => $value) {
                if (is_string($value)) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
        }

        $prompt .= "\n---\nDraft to refine:\n---\n\n{$draftContent}";

        return $prompt;
    }

    /**
     * Get social media system prompt.
     */
    protected function getSocialSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a social media specialist for Host UK. Create engaging social posts that:

- Hook attention in the first line
- Provide genuine value (no filler)
- Use appropriate tone for each platform
- Include clear but non-salesy CTAs
- Follow UK English conventions
- Never use excessive emojis or hashtags

For each platform, respect character limits and norms:
- Twitter/X: 280 chars, conversational, can use threads
- LinkedIn: Professional, longer form OK, no hashtag spam
- Facebook: Casual, engagement-focused
- Instagram: Visual-focused copy, strategic hashtags
PROMPT;
    }

    /**
     * Build the social posts prompt.
     */
    protected function buildSocialPrompt(string $sourceContent, array $platforms): string
    {
        $platformList = implode(', ', $platforms);

        return <<<PROMPT
Create social media posts for these platforms: {$platformList}

Base the posts on this content:
---
{$sourceContent}
---

For each platform, provide:
1. Main post text
2. Optional call-to-action
3. Suggested posting time (UK timezone)

Output as JSON:
```json
{
  "posts": [
    {
      "platform": "twitter",
      "content": "...",
      "cta": "...",
      "suggested_time": "..."
    }
  ]
}
```
PROMPT;
    }

    /**
     * Get help article specific prompt.
     */
    protected function getHelpArticlePrompt(): string
    {
        return <<<'PROMPT'
For help articles, include:

1. **Overview** - What this article covers and who it's for
2. **Prerequisites** - What the user needs before starting
3. **Step-by-step instructions** - Clear, numbered steps with expected outcomes
4. **Screenshots placeholders** - [Screenshot: description]
5. **Troubleshooting** - Common issues and solutions
6. **Pro tips** - Advanced tips for power users
7. **Related articles** - Links to related help content
8. **FAQ** - Common questions about this topic

Frontmatter should include:
- difficulty: beginner|intermediate|advanced
- estimated_time: X minutes
- prerequisites: [list]
PROMPT;
    }

    /**
     * Get blog post specific prompt.
     */
    protected function getBlogPostPrompt(): string
    {
        return <<<'PROMPT'
For blog posts, include:

1. **Hook** (first 100 words) - Grab attention, state the problem
2. **Key takeaways** - Bulleted summary for skimmers
3. **Introduction** - Context and what reader will learn
4. **Main sections** (3-5) - H2 headings with H3 subsections
5. **Actionable tips** - Numbered practical advice
6. **Data/statistics** - Include relevant UK or industry stats
7. **Examples** - Real-world applications
8. **Conclusion** - Summary and next steps
9. **CTA** - Clear call to action

Frontmatter should include:
- reading_time: X min
- category: [category]
- tags: [list]
PROMPT;
    }

    /**
     * Get landing page specific prompt.
     */
    protected function getLandingPagePrompt(): string
    {
        return <<<'PROMPT'
For landing pages, include:

1. **Hero section** - Compelling headline, subheadline, primary CTA
2. **Problem statement** - Pain points the audience faces
3. **Solution overview** - How we solve the problem
4. **Key features** - 3-5 main features with benefits
5. **Social proof** - Testimonial placeholders, stats
6. **How it works** - Simple 3-step process
7. **Pricing CTA** - Clear pricing or trial offer
8. **FAQ section** - Address common objections
9. **Final CTA** - Strong closing call to action

Focus on benefits over features. Make CTAs feel natural, not pushy.
PROMPT;
    }

    /**
     * Get social post specific prompt.
     */
    protected function getSocialPostPrompt(): string
    {
        return <<<'PROMPT'
For social posts, create content for multiple platforms:

1. **Twitter/X** - 280 chars max, punchy, conversational
2. **LinkedIn** - Professional, can be longer, thought leadership
3. **Facebook** - Casual, engagement-focused
4. **Instagram caption** - Visual-focused, strategic hashtags

Each post should:
- Stand alone as valuable content
- Include appropriate CTA
- Respect platform character limits and norms
PROMPT;
    }

    /**
     * Get the Gemini service instance.
     *
     * Reads config fresh on each call (unless override was provided in constructor)
     * to support runtime config changes.
     */
    protected function getGemini(): GeminiService
    {
        $apiKey = $this->geminiApiKeyOverride ?? config('services.google.ai_api_key');
        $model = $this->geminiModelOverride ?? config('services.google.ai_model', 'gemini-2.0-flash');

        if (empty($apiKey)) {
            throw new RuntimeException('Gemini API key not configured');
        }

        // Reset cached instance if config has changed
        if ($this->gemini !== null) {
            return $this->gemini;
        }

        return $this->gemini = new GeminiService($apiKey, $model);
    }

    /**
     * Get the Claude service instance.
     *
     * Reads config fresh on each call (unless override was provided in constructor)
     * to support runtime config changes.
     */
    protected function getClaude(): ClaudeService
    {
        $apiKey = $this->claudeApiKeyOverride ?? config('services.anthropic.api_key');
        $model = $this->claudeModelOverride ?? config('services.anthropic.model', 'claude-sonnet-4-20250514');

        if (empty($apiKey)) {
            throw new RuntimeException('Claude API key not configured');
        }

        // Reset cached instance if config has changed
        if ($this->claude !== null) {
            return $this->claude;
        }

        return $this->claude = new ClaudeService($apiKey, $model);
    }

    /**
     * Check if both AI providers are available.
     *
     * Reads config fresh to reflect runtime changes.
     */
    public function isAvailable(): bool
    {
        return $this->isGeminiAvailable() && $this->isClaudeAvailable();
    }

    /**
     * Check if Gemini is available.
     *
     * Reads config fresh to reflect runtime changes.
     */
    public function isGeminiAvailable(): bool
    {
        $apiKey = $this->geminiApiKeyOverride ?? config('services.google.ai_api_key');

        return ! empty($apiKey);
    }

    /**
     * Check if Claude is available.
     *
     * Reads config fresh to reflect runtime changes.
     */
    public function isClaudeAvailable(): bool
    {
        $apiKey = $this->claudeApiKeyOverride ?? config('services.anthropic.api_key');

        return ! empty($apiKey);
    }

    /**
     * Reset cached service instances.
     *
     * Call this if config changes at runtime and you need fresh instances.
     */
    public function resetServices(): void
    {
        $this->gemini = null;
        $this->claude = null;
    }
}
