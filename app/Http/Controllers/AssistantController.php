<?php

namespace App\Http\Controllers;

use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use AgenticActions\Streaming\Transcript;
use App\Ai\Agents\BoardAssistant;
use App\Ai\ScriptedPlanner;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The board assistant's chat endpoint, after docs/copilot.md in agentic-actions. Both routes sit inside the starter
 * kit's {current_team} group, so auth, verified and EnsureTeamMembership run first: a person reaches only the
 * assistant of a team they belong to. Actions::conversation() keeps one conversation per user, team and agent in the
 * package's agentic_conversations table, keyed only by what the server passes.
 */
class AssistantController extends Controller
{
    /**
     * How long one turn may hold its lock if it never gets to release it: the longest a request may run on the host.
     */
    private const TURN_SECONDS = 60;

    /**
     * One turn: the newest words from useChat, or the person's answer to a confirmation card, with the history from the
     * conversation store, and the reply streamed through ActionsProtocol, whose data-action rows the panel shows while
     * the actions run. The server picks the conversation before it reads the request, so an answer counts only for a
     * card this person's conversation in this team is waiting on.
     *
     * One reply at a time per person and team: a turn holds a cache lock until its stream ends, and a second one sent
     * meanwhile (another tab, or new words after Stop, which ActionsProtocol lets the first turn finish) answers 429
     * with one sentence, which the panel shows.
     */
    public function stream(Request $request, Team $currentTeam): Responsable|Response
    {
        /** @var User $user */
        $user = $request->user();
        $turn = Cache::lock("assistant:{$user->id}:{$currentTeam->id}", self::TURN_SECONDS);

        if (! $turn->get()) {
            return response()->json(['message' => __('The assistant is still answering. Wait for that reply, then send again.')], 429);
        }

        try {
            $conversation = Actions::conversation(BoardAssistant::class, $user, $currentTeam);
            $assistant = (new BoardAssistant($user, $currentTeam))->continueOrStart($conversation->id(), $user);

            // 422 for no words and no answer, 409 for an answer to a card that is no longer waiting.
            $response = ChatRequest::from($request, $assistant)->respond(function (ChatRequest $chat) use ($assistant, $conversation, $user, $currentTeam) {
                $assistant->continue($conversation->open("{$currentTeam->name} board"), as: $user);

                if (ScriptedPlanner::active()) {
                    (new ScriptedPlanner($assistant))->drive((string) $chat->message()?->content);
                }

                return $assistant->stream($chat)->usingProtocol(new ActionsProtocol(messageId: $chat->messageId()));
            });
        } catch (Throwable $exception) {
            $turn->release();

            throw $exception;
        }

        // A streamed turn runs while its response is sent, so it holds the lock until the stream ends or fails.
        if ($response instanceof StreamableAgentResponse) {
            return $response->then(fn () => $turn->release())->catch(fn () => $turn->release());
        }

        $turn->release();

        return $response;
    }

    /**
     * This user's conversation in this team, for the panel to show again after a reload: the words, and the card of a
     * delete still waiting for them, rebuilt on the server from the task. Also whether the assistant runs in demo mode, and
     * the longest message the endpoint reads, for the message box.
     */
    public function transcript(Request $request, Team $currentTeam): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $conversationId = Actions::conversation(BoardAssistant::class, $user, $currentTeam)->id();

        return response()->json([
            'messages' => $conversationId === null ? [] : Transcript::forUseChat(
                $conversationId,
                $user,
                agent: (new BoardAssistant($user, $currentTeam))->continue($conversationId, as: $user),
            ),
            'demo' => ScriptedPlanner::active(),
            'max_length' => (int) config('agentic-actions.agents.max_message_length', 4000),
        ]);
    }
}
