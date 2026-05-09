<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login'    => ['required', 'string', 'regex:/^\d+-\d+-\d+$/'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.regex' => 'El formato debe ser id-propietarioId-restauranteId (ej: 3-1-2)',
        ];
    }
}
