<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission($this->isMethod('POST') ? 'contacts.create' : 'contacts.edit');
    }

    public function rules(): array
    {
        $sometimes = $this->isMethod('PATCH') || $this->isMethod('PUT') ? 'sometimes' : 'nullable';
        $id = $this->route('contact')?->id;

        return [
            'first_name' => [$sometimes, 'string', 'max:120'],
            'last_name' => [$sometimes, 'string', 'max:120'],
            'email' => [$sometimes, 'nullable', 'email:rfc', 'max:255', Rule::unique('contacts', 'email')->ignore($id)->whereNull('deleted_at')],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'email_secondary' => ['nullable', 'email', 'max:255'],
            'emails' => ['nullable', 'array', 'max:10'],
            'emails.*.email' => ['required', 'email', 'max:255'],
            'emails.*.type' => ['nullable', 'string', 'max:30'],
            'phones' => ['nullable', 'array', 'max:10'],
            'phones.*.number' => ['required', 'string', 'max:40'],
            'phones.*.ext' => ['nullable', 'string', 'max:10'],
            'phones.*.type' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:40'],
            'phone_secondary' => ['nullable', 'string', 'max:40'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'contact_type' => ['nullable', 'string', 'max:50'],
            'birthday' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
            'email_opt_out' => ['sometimes', 'boolean'],
            'addresses' => ['nullable', 'array', 'max:5'],
            'addresses.*.street1' => ['nullable', 'string'],
            'addresses.*.city' => ['nullable', 'string'],
            'addresses.*.state' => ['nullable', 'string'],
            'addresses.*.zip' => ['nullable', 'string'],
            'addresses.*.country' => ['nullable', 'string'],
            'custom_fields' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:120'],
        ];
    }
}
