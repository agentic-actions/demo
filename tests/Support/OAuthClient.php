<?php

namespace Tests\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * A remote MCP client signing in the way a Claude custom connector does, in one process: dynamic registration with
 * Claude's callback, the authorization request with the team's MCP URL as resource and S256 PKCE, the person's answer
 * on the consent screen, the code for a token pair, and a refresh.
 */
final class OAuthClient
{
    /**
     * Claude's callback for claude.ai, Desktop and mobile.
     */
    public const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * The client id registration gave.
     */
    public string $id = '';

    /**
     * The PKCE verifier of the last authorization request.
     */
    private string $verifier = '';

    /**
     * The last token pair.
     *
     * @var array{access_token?: string, refresh_token?: string}
     */
    public array $tokens = [];

    /**
     * Register the client, as Claude does on every new connection.
     */
    public function __construct(private readonly TestCase $test, string $name = 'Claude')
    {
        $this->id = (string) $test->postJson('/oauth/register', [
            'client_name' => $name,
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
    }

    /**
     * Ask the person, signed in, to approve this client for the team's MCP URL.
     *
     * @return TestResponse<Response>
     */
    public function authorize(User $person, Team $team, string $scope = 'actions:read actions:write'): TestResponse
    {
        $this->verifier = Str::random(64);

        // Passport's controller keeps the guard it was built with, and one test's requests share one application.
        Route::getRoutes()->getByName('passport.authorizations.authorize')?->flushController();

        return $this->test->actingAs($person)->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->id,
            'redirect_uri' => self::REDIRECT,
            'scope' => $scope,
            'state' => 'st123',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => Boards::mcpUrl($team),
        ]));
    }

    /**
     * Post one of the screen's answers, as its native form would: approve or deny, with the page's fields.
     *
     * @param  array{url: string, method: string, fields: array<string, string>}  $answer
     * @return TestResponse<Response>
     */
    public function answer(array $answer): TestResponse
    {
        return $this->test->post($answer['url'], $answer['fields']);
    }

    /**
     * Exchange the code an approval redirected with for a token pair, with the verifier and the resource.
     *
     * @param  TestResponse<Response>  $approval
     * @return TestResponse<Response>
     */
    public function token(TestResponse $approval, Team $team): TestResponse
    {
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);

        return $this->keep($this->test->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->id,
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'] ?? '',
            'code_verifier' => $this->verifier,
            'resource' => Boards::mcpUrl($team),
        ]));
    }

    /**
     * Refresh the pair.
     *
     * @return TestResponse<Response>
     */
    public function refresh(): TestResponse
    {
        return $this->keep($this->test->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->id,
            'refresh_token' => $this->tokens['refresh_token'] ?? '',
        ]));
    }

    /**
     * Sign in, approve and get a token pair for the team, in one go.
     */
    public function connect(User $person, Team $team, string $scope = 'actions:read actions:write'): self
    {
        $consent = $this->authorize($person, $team, $scope)->assertOk();
        $this->token($this->answer($consent->inertiaProps('approve')), $team)->assertOk();

        return $this;
    }

    /**
     * One MCP request with the access token, as a new client would send it: without the session a test signed in.
     *
     * @param  array<string, mixed>  $params
     * @return TestResponse<Response>
     */
    public function mcp(Team $team, string $method, array $params = []): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->test->postJson(Boards::mcpUrl($team), Boards::rpc($method, $params), Boards::bearer($this->tokens['access_token'] ?? ''));
    }

    /**
     * Keep the pair a token answer issued.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    private function keep(TestResponse $response): TestResponse
    {
        if ($response->isOk()) {
            $this->tokens = $response->json();
        }

        return $response;
    }
}
