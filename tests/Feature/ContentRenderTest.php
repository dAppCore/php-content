<?php

use Core\Mod\Content\Services\ContentRender;
use Core\Tenant\Models\Workspace;
use Illuminate\Http\Request;

beforeEach(function () {
    // Create a test workspace
    $this->workspace = Workspace::create([
        'name' => 'Link Test',
        'slug' => 'link',
        'domain' => 'link.host.uk.com',
        'icon' => 'link',
        'color' => 'violet',
        'description' => 'A test workspace',
        'type' => 'wordpress',
        'is_active' => true,
        'sort_order' => 1,
    ]);
});

describe('FindDomainRecord Middleware', function () {
    it('ignores core domains and serves normal routes', function () {
        // Hub routes should still work on main domain
        $this->get('/')
            ->assertOk();
    });
});

describe('ContentRender Service', function () {
    it('renders waitlist view when workspace is inactive', function () {
        $this->workspace->update(['is_active' => false]);

        $request = Request::create('/', 'GET');
        $request->attributes->set('workspace_model', $this->workspace);

        $render = app(ContentRender::class);
        $response = $render->home($request);

        expect($response->name())->toBe('web::waitlist');
    });

    it('renders home view even when workspace has no content', function () {
        $request = Request::create('/', 'GET');
        $request->attributes->set('workspace_model', $this->workspace);

        $render = app(ContentRender::class);
        $response = $render->home($request);

        // With native content, home view is rendered even when empty
        // (waitlist is only for inactive workspaces)
        expect($response->name())->toBe('web::home');
    });

    it('adds email to waitlist', function () {
        $render = app(ContentRender::class);

        $result = $render->addToWaitlist($this->workspace, 'test@example.com');

        expect($result)->toBeTrue();

        $waitlist = $render->getWaitlist($this->workspace);
        expect($waitlist)->toContain('test@example.com');
    });

    it('does not duplicate emails in waitlist', function () {
        $render = app(ContentRender::class);

        $render->addToWaitlist($this->workspace, 'test@example.com');
        $result = $render->addToWaitlist($this->workspace, 'test@example.com');

        expect($result)->toBeFalse();

        $waitlist = $render->getWaitlist($this->workspace);
        expect(count(array_filter($waitlist, fn ($e) => $e === 'test@example.com')))->toBe(1);
    });

    it('returns empty array for posts when content is unavailable', function () {
        $render = app(ContentRender::class);

        $posts = $render->getPosts($this->workspace);

        expect($posts)->toBeArray()
            ->and($posts['posts'])->toBeArray();
    });

    it('resolves workspace from request attributes', function () {
        $request = Request::create('/', 'GET');
        $request->attributes->set('workspace_model', $this->workspace);

        $render = app(ContentRender::class);
        $resolved = $render->resolveWorkspace($request);

        expect($resolved)->not->toBeNull()
            ->and($resolved->id)->toBe($this->workspace->id);
    });
});

describe('Workspace Model', function () {
    it('can find workspace by slug', function () {
        $found = Workspace::where('slug', 'link')->first();

        expect($found)->not->toBeNull()
            ->and($found->name)->toBe('Link Test')
            ->and($found->domain)->toBe('link.host.uk.com');
    });

    it('can find workspace by domain', function () {
        $found = Workspace::where('domain', 'link.host.uk.com')->first();

        expect($found)->not->toBeNull()
            ->and($found->slug)->toBe('link');
    });

    it('scopes active workspaces', function () {
        $active = Workspace::active()->where('slug', 'link')->first();
        expect($active)->not->toBeNull();

        $this->workspace->update(['is_active' => false]);

        $inactive = Workspace::active()->where('slug', 'link')->first();
        expect($inactive)->toBeNull();
    });
});
