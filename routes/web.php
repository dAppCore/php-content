<?php

declare(strict_types=1);

use Core\Mod\Content\View\Modal\Web\Blog;
use Core\Mod\Content\View\Modal\Web\Help;
use Core\Mod\Content\View\Modal\Web\HelpArticle;
use Core\Mod\Content\View\Modal\Web\Post;
use Core\Mod\Content\View\Modal\Web\Preview;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Content Module Web Routes
|--------------------------------------------------------------------------
|
| Public satellite pages for blog and help content.
|
*/

Route::get('/blog', Blog::class)->name('satellite.blog');
Route::get('/blog/{slug}', Post::class)->name('satellite.post');
Route::get('/help', Help::class)->name('satellite.help');
Route::get('/help/{slug}', HelpArticle::class)->name('satellite.help.article');

/*
|--------------------------------------------------------------------------
| Content Preview Routes
|--------------------------------------------------------------------------
|
| Preview draft/unpublished content with time-limited tokens.
|
*/

Route::get('/content/preview/{item}', Preview::class)->name('content.preview');
