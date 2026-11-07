<?php

namespace App\Http\Requests;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The body of `PUT /api/v1/posts/{slug}`. Every field is optional, and a
 * field that is left out keeps its stored value. Authorization is the
 * token's and the account's business, decided in `PostUpsert`, so this class
 * only judges the shape of the request.
 */
class UpsertPostRequest extends FormRequest
{
    /** RFC 3339 with a timezone, such as 2026-11-17T01:00:00Z or 2026-11-17T08:00:00+07:00. */
    private const RFC_3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/i';

    /** The limit of the admin's tag picker. */
    private const MAX_TAGS = 6;

    /** The top-level fields of the body. */
    private const FIELDS = ['status', 'published_at', 'tags', 'en', 'id'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'status' => ['sometimes', Rule::enum(PostStatus::class)],
            'published_at' => ['sometimes', 'nullable', 'string', 'regex:'.self::RFC_3339, 'date'],
            'tags' => ['sometimes', 'array', 'max:'.self::MAX_TAGS],
            'tags.*' => ['string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:255'],
            'en' => ['sometimes', 'array'],
            'id' => ['sometimes', 'array'],
        ];

        foreach (Post::LANGUAGES as $lang) {
            $rules["{$lang}.title"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$lang}.description"] = ['sometimes', 'nullable', 'string', 'max:5000'];
            $rules["{$lang}.body"] = ['sometimes', 'nullable', 'string', 'max:2000000'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'published_at.regex' => 'The date must be RFC 3339 with a timezone, such as 2026-11-17T01:00:00Z.',
            'tags.*.regex' => 'A tag is named by its slug, in lowercase letters, digits and single hyphens.',
        ];
    }

    /**
     * A field the API does not know is refused, not ignored, so a typo such as
     * `tag` for `tags` fails loudly instead of quietly doing nothing.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $body = $this->request->all();

                foreach (array_diff(array_keys($body), self::FIELDS) as $field) {
                    $validator->errors()->add((string) $field, "The field \"{$field}\" is not part of the API.");
                }

                foreach (Post::LANGUAGES as $lang) {
                    if (! is_array($body[$lang] ?? null)) {
                        continue;
                    }
                    foreach (array_diff(array_keys($body[$lang]), Post::LANGUAGE_FIELDS) as $field) {
                        $validator->errors()->add("{$lang}.{$field}", "The field \"{$lang}.{$field}\" is not part of the API.");
                    }
                }
            },
        ];
    }
}
