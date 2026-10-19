<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as BaseValidator;

/**
 * Catches a form field that is sent but never saved.
 *
 * Laravel's validated() quietly leaves out every field that has no rule, so a form can send "address type" for months while the server throws it
 * away and nobody gets an error. In every environment but production, the validator is swapped for one that notes which fields of the request were
 * not in its rules, and the response says so in an `X-Ignored-Input` header (and the log), so the end-to-end tests and anyone using the app while
 * building can see it at once. The list of fields it is fine to send without a rule (form helpers, the client's own bookkeeping) is in `config/ignored_input.php`.
 */
class IgnoredInput
{
    /** @var list<string> */
    private static array $found = [];

    public static function install(): void
    {
        Validator::resolver(fn ($translator, $data, $rules, $messages, $attributes) => new class($translator, $data, $rules, $messages, $attributes) extends BaseValidator
        {
            public function validated(): array
            {
                $validated = parent::validated();
                IgnoredInput::note($this->getData(), $validated);

                return $validated;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $sent
     * @param  array<string, mixed>  $validated
     */
    public static function note(array $sent, array $validated): void
    {
        $request = request();
        if ($request->isMethod('GET') || $sent !== $request->all() && $sent !== $request->input()) {
            return;                                                 // a validator for something other than what the request carried
        }
        $allowed = (array) config('ignored_input.allowed', []);
        $kept = array_keys(Arr::dot($validated));
        foreach (array_keys(Arr::dot($sent)) as $key) {
            $generic = preg_replace('/\.\d+(?=\.|$)/', '.*', $key);
            if (in_array($key, $kept, true) || str_starts_with($key, '_') || array_filter($allowed, fn ($a) => fnmatch($a, $generic) || fnmatch($a, $key))) {
                continue;
            }
            self::$found[] = $generic;
        }
    }

    /** @return list<string> */
    public static function take(): array
    {
        $found = array_values(array_unique(self::$found));
        self::$found = [];

        return $found;
    }
}
