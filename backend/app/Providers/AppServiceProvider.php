<?php

namespace App\Providers;

use App\Models\Pharmacy;
use App\Services\SoapReminders;
use Illuminate\Support\Facades\View;
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
        View::composer(['partials.soap-notifications', 'partials.soap-reminder-card'], function ($view) {
            $request = request();
            $pharmacy = $request->route('pharmacy');
            $member = $request->attributes->get('membership');
            $summary = null;
            if ($pharmacy instanceof Pharmacy && SoapReminders::eligible($member)) {
                $key = 'soap.reminders.'.$pharmacy->id.'.'.$member->user_id;
                $summary = $request->attributes->get($key);
                if ($summary === null) {
                    $summary = SoapReminders::summary($pharmacy, $member);
                    $request->attributes->set($key, $summary);
                }
            }
            $view->with('soapReminders', $summary);
        });
    }
}
