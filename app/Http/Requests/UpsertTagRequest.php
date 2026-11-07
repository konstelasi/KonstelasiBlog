<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `PUT /api/v1/tags/{slug}`, the two names of a tag. Who may
 * create one is the token's and the account's business, decided in the
 * controller, so this class only judges the shape of the request.
 */
class UpsertTagRequest extends FormRequest
{
    /** The fields of the body. */
    private const FIELDS = ['en', 'id'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'en' => ['required', 'string', 'max:255'],
            'id' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * A field the API does not know is refused, not ignored, so a typo fails
     * loudly instead of quietly doing nothing.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->request->all()), self::FIELDS) as $field) {
                    $validator->errors()->add((string) $field, "The field \"{$field}\" is not part of the API.");
                }
            },
        ];
    }
}
