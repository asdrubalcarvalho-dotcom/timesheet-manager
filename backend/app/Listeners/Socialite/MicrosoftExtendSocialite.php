<?php

namespace App\Listeners\Socialite;

use SocialiteProviders\Azure\Provider as AzureProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class MicrosoftExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        // The upstream provider registers as the 'azure' driver.
        // We alias it as 'microsoft' to keep route/provider naming consistent in this app.
        $socialiteWasCalled->extendSocialite('microsoft', AzureProvider::class);
    }
}
