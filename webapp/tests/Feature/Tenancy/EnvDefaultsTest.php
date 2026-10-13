<?php

namespace Tests\Feature\Tenancy;

use App\Models\PlatformSetting;
use App\Services\Tenancy\MailSettings;
use App\Services\Tenancy\SmsSettings;
use Tests\TenantTestCase;

/** The .env file gives the platform's Twilio and SendGrid defaults; whatever a platform administrator saves replaces them. */
class EnvDefaultsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::forgetCache();
    }

    public function test_with_nothing_in_env_or_saved_text_messages_are_off(): void
    {
        $sms = app(SmsSettings::class);

        $this->assertFalse($sms->enabled());
        $this->assertNull($sms->account());
    }

    public function test_a_complete_twilio_account_in_env_is_used_and_on_by_default(): void
    {
        config(['services.twilio.account_sid' => 'ACenv0000000000', 'services.twilio.auth_token' => 'envtoken', 'services.twilio.from_number' => '+15550001111']);

        $account = app(SmsSettings::class)->account();

        $this->assertSame(['ACenv0000000000', 'envtoken', '+15550001111'], [$account['sid'], $account['token'], $account['from']]);
        $this->assertTrue(app(SmsSettings::class)->current()['env']['token_set']);
    }

    public function test_saved_values_replace_env_and_empty_ones_fall_back_to_it(): void
    {
        config(['services.twilio.account_sid' => 'ACenv0000000000', 'services.twilio.auth_token' => 'envtoken', 'services.twilio.from_number' => '+15550001111']);
        $sms = app(SmsSettings::class);

        $sms->save(['enabled' => true, 'account_sid' => 'ACsaved000000000', 'auth_token' => 'savedtoken', 'from_number' => '+15559998888']);
        PlatformSetting::forgetCache();
        $this->assertSame(['ACsaved000000000', 'savedtoken', '+15559998888'], array_values(array_intersect_key($sms->account(), array_flip(['sid', 'token', 'from']))));

        $sms->save(['enabled' => true, 'account_sid' => '', 'clear_token' => true, 'from_number' => '']);
        PlatformSetting::forgetCache();
        $this->assertSame(['ACenv0000000000', 'envtoken', '+15550001111'], array_values(array_intersect_key($sms->account(), array_flip(['sid', 'token', 'from']))));
    }

    public function test_switching_texts_off_on_the_settings_page_beats_env(): void
    {
        config(['services.twilio.account_sid' => 'ACenv0000000000', 'services.twilio.auth_token' => 'envtoken', 'services.twilio.from_number' => '+15550001111']);
        app(SmsSettings::class)->save(['enabled' => false]);
        PlatformSetting::forgetCache();

        $this->assertNull(app(SmsSettings::class)->account());
    }

    public function test_the_sendgrid_verification_key_comes_from_env_until_one_is_saved(): void
    {
        $mail = app(MailSettings::class);
        $this->assertFalse($mail->sendgridVerificationKeySet());

        config(['services.sendgrid.public_key' => "MFkwEwYH\nKoZIzj0C"]);
        $this->assertTrue($mail->sendgridVerificationKeySet());
        $this->assertTrue($mail->sendgridKeyFromEnv());
        $this->assertSame('MFkwEwYHKoZIzj0C', $mail->sendgridPublicKey());

        $mail->saveSendgridVerificationKey('SAVEDKEY');
        PlatformSetting::forgetCache();
        $this->assertSame('SAVEDKEY', $mail->sendgridPublicKey());
        $this->assertFalse($mail->sendgridKeyFromEnv());
    }
}
