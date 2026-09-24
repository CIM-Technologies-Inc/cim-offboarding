<?php

namespace App\Providers;

use App\Listeners\RedirectMailInNonProduction;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only ever active when mail.redirect_to (env MAIL_REDIRECT_TO) is
        // set — see RedirectMailInNonProduction's own docblock. A no-op in
        // production, where that env var must stay unset.
        Event::listen(MessageSending::class, RedirectMailInNonProduction::class);
    }
}
