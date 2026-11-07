<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertTagRequest;
use App\Models\Tag;
use App\Rules\HouseStyle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The tags side of the authoring API. A tag is its own resource, apart from
 * the posts that carry it. A post write only ever uses tags that exist, so a
 * typo in a post cannot make one, and making one takes the `tags:manage`
 * ability and an account that holds `tag.manage`. It only creates. Renaming
 * a tag changes pages that may be live, so it stays an admin action.
 */
class TagController extends Controller
{
    /** Every tag by slug, so a client can check the tags of a post before it writes. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Tag::orderBy('slug')->get()->map(fn (Tag $tag): array => $this->present($tag))->all(),
        ]);
    }

    /**
     * Create the tag at this slug. Repeating a request with the same names
     * changes nothing the second time, and other names for an existing slug
     * are refused. With `?dry_run=1` every check runs and nothing is written.
     */
    public function upsert(UpsertTagRequest $request, string $slug): JsonResponse
    {
        $user = $request->user();
        $dryRun = $request->boolean('dry_run');

        if ($user->cannot('create', Tag::class)) {
            return $this->refuse('not_allowed', 'Your account may not create tags. Ask an Editor or an Admin.', status: 403);
        }

        $names = $request->validated();

        // The names are printed on live posts, so they pass the rule the Tags form applies.
        $problems = [];
        foreach (['en', 'id'] as $lang) {
            $found = HouseStyle::problems($names[$lang], HouseStyle::TITLE);
            if ($found !== []) {
                $problems[$lang] = $found;
            }
        }
        if ($problems !== []) {
            return $this->refuse('house_style', 'A tag name breaks the house writing style.', $problems);
        }

        $existing = Tag::where('slug', $slug)->first();
        if ($existing !== null) {
            return $this->answerForExisting($existing, $names, $dryRun);
        }

        if ($dryRun) {
            return $this->created(new Tag(['slug' => $slug, 'name_en' => $names['en'], 'name_id' => $names['id']]), true, 200);
        }

        try {
            $tag = Tag::create(['slug' => $slug, 'name_en' => $names['en'], 'name_id' => $names['id']]);
        } catch (UniqueConstraintViolationException) {
            // Someone created it between the check and the insert.
            return $this->answerForExisting(Tag::where('slug', $slug)->firstOrFail(), $names, $dryRun);
        }

        Log::info('The authoring API created a tag.', $this->context($request, $slug));

        return $this->created($tag, false, 201);
    }

    /**
     * @param  array<string, string>  $names
     */
    private function answerForExisting(Tag $tag, array $names, bool $dryRun): JsonResponse
    {
        if ($tag->name_en === $names['en'] && $tag->name_id === $names['id']) {
            return response()->json([
                'data' => $this->present($tag),
                'meta' => ['action' => 'unchanged', 'dry_run' => $dryRun],
            ]);
        }

        return $this->refuse(
            'tag_exists',
            'A tag with this slug exists under other names. Renaming happens in the admin, because a tag is printed on live posts.',
            ['en' => [$tag->name_en], 'id' => [$tag->name_id]],
            status: 409,
        );
    }

    private function created(Tag $tag, bool $dryRun, int $status): JsonResponse
    {
        return response()->json([
            'data' => $this->present($tag),
            'meta' => ['action' => 'created', 'dry_run' => $dryRun],
        ], $status);
    }

    /**
     * @return array{slug: string, en: string, id: string}
     */
    private function present(Tag $tag): array
    {
        return ['slug' => $tag->slug, 'en' => $tag->name_en, 'id' => $tag->name_id];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function refuse(string $code, string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'code' => $code,
            'errors' => $errors ?: null,
        ]), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request, string $slug): array
    {
        return [
            'slug' => $slug,
            'account' => $request->user()->getKey(),
            'token' => $request->user()->currentAccessToken()?->name,
        ];
    }
}
