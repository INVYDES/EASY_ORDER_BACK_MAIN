<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any visitor can attempt login
    }

    public function rules(): array
    {
        return [
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
