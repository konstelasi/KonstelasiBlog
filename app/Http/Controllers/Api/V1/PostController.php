<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPostRequest;
use App\Http\Resources\ManagedPostResource;
use App\Models\Post;
use App\Support\PostRefused;
use App\Support\PostUpsert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The authoring API. A token stands for an account, the account's role
 * decides what it may do, and the token's abilities narrow that further. The
 * rules of a write are in `PostUpsert`, so this class only moves data in and
 * out. `GET /api/posts`, which the site build reads, is a different
 * controller and stays public and unchanged.
 */
class PostController extends Controller
{
    /** One post of any status, for an account that may view posts. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $post = Post::with(['writer', 'tags'])->where('slug', $slug)->first();

        if ($post === null) {
            return response()->json(['message' => 'No post has this slug.', 'code' => 'not_found'], 404);
        }

        if ($request->user()->cannot('view', $post)) {
            return response()->json(['message' => 'Your account may not read this post.', 'code' => 'not_allowed'], 403);
        }

        return response()->json([
            'data' => ManagedPostResource::make($post)->withTags($post->tags->pluck('slug')->sort()->values()->all())->resolve(),
        ]);
    }

    /**
     * Create or update the post at this slug, the same way every time it is
     * repeated. With `?dry_run=1` every check runs and nothing is written, so
     * the answer says what a real write would do.
     */
    public function upsert(UpsertPostRequest $request, PostUpsert $upsert, string $slug): JsonResponse
    {
        $user = $request->user();
        $dryRun = $request->boolean('dry_run');

        try {
            $result = $upsert->handle($user, $slug, $request->validated(), $dryRun);
        } catch (PostRefused $refused) {
            Log::info('The authoring API refused a post.', [
                'slug' => $slug,
                'problem' => $refused->problem,
                'account' => $user->getKey(),
                'token' => $user->currentAccessToken()?->name,
            ]);

            return response()->json(array_filter([
                'message' => $refused->getMessage(),
                'code' => $refused->problem,
                'errors' => $refused->errors ?: null,
            ]), $refused->status);
        }

        if (! $dryRun && $result->action !== 'unchanged') {
            Log::info('The authoring API wrote a post.', [
                'slug' => $slug,
                'action' => $result->action,
                'changes' => $result->changes,
                'account' => $user->getKey(),
                'token' => $user->currentAccessToken()?->name,
            ]);
        }

        return response()->json([
            'data' => ManagedPostResource::make($result->post)->withTags($result->tags)->resolve(),
            'meta' => [
                'action' => $result->action,
                'changes' => $result->changes,
                'warnings' => $result->warnings,
                'dry_run' => $result->dryRun,
                'site_rebuild' => $result->siteRebuild,
            ],
        ], $result->action === 'created' && ! $dryRun ? 201 : 200);
    }
}
