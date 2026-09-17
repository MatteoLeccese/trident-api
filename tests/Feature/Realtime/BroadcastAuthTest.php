<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Src\Game\Domain\Model\Game;
use Src\Game\Domain\Model\SeatRoster;
use Src\Game\Domain\Repository\GameRepository;
use Src\Game\Domain\ValueObjects\ControllerToken;
use Src\Game\Domain\ValueObjects\GameId;
use Src\Game\Domain\ValueObjects\JoinCode;
use Src\Game\Domain\ValueObjects\Nickname;
use Src\Shared\Infrastructure\Service\FrozenClock;
use Tests\Doubles\InMemoryGameRepository;
use Tests\TestCase;

/**
 * Channel authorisation: the exact point where the old code died, broadcasting on
 * a private channel with no authorisation callback, in a product with no users —
 * unsatisfiable by construction.
 *
 * This test forces a REAL broadcast driver. With the `null` that phpunit.xml
 * brings, `auth()` is an empty method: it would return 200 without running
 * anything and the test would be decorative.
 */
final class BroadcastAuthTest extends TestCase
{
    private InMemoryGameRepository $games;

    private ControllerToken $token;

    private Game $game;

    /**
     * The driver has to be set BEFORE the app boots, not in setUp().
     *
     * Channels are registered on the **broadcaster**, not on the manager
     * (`Broadcaster::$channels`), and `driver()` caches by name. Changing
     * `broadcasting.default` once booted leaves the channels registered on the old
     * driver and resolves a new one with an empty list: 403 on everything.
     */
    public function createApplication(): Application
    {
        putenv('BROADCAST_CONNECTION=reverb');
        $_ENV['BROADCAST_CONNECTION'] = 'reverb';
        $_SERVER['BROADCAST_CONNECTION'] = 'reverb';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->games = new InMemoryGameRepository;
        $this->app->instance(GameRepository::class, $this->games);

        $this->token = ControllerToken::generate();
        $this->game = Game::open(
            GameId::random(),
            JoinCode::generate(),
            $this->token,
            SeatRoster::fromNicknames(array_map(Nickname::fromString(...), ['Ana', 'Bea', 'Caro'])),
            FrozenClock::at('2026-09-16 20:00:00'),
        );
        $this->games->save($this->game);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function authorise(string $channel, array $headers = []): TestResponse
    {
        // pusher-js sends form-urlencoded, never JSON.
        return $this->post('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => $channel,
        ], $headers);
    }

    private function channel(): string
    {
        return 'presence-game.'.$this->game->id()->value();
    }

    /**
     * @return array<string, mixed>
     */
    private function userInfo(TestResponse $response): array
    {
        $channelData = json_decode((string) $response->json('channel_data'), true);

        return $channelData['user_info'];
    }

    public function test_a_television_with_no_token_may_watch(): void
    {
        // This is the NORMAL path for the television. A missing token is not an error.
        $this->authorise($this->channel())
            ->assertOk()
            ->assertJsonStructure(['auth', 'channel_data']);
    }

    public function test_the_spectator_is_identified_as_a_spectator(): void
    {
        $this->assertSame('spectator', $this->userInfo($this->authorise($this->channel()))['role']);
    }

    public function test_the_phone_holding_the_token_is_identified_as_the_controller(): void
    {
        $response = $this->authorise($this->channel(), [
            'X-Trident-Controller-Token' => $this->token->value(),
        ])->assertOk();

        $this->assertSame('controller', $this->userInfo($response)['role']);
    }

    public function test_a_wrong_token_still_watches_but_is_not_the_controller(): void
    {
        // There is nothing to deny: nothing is written through the socket.
        $response = $this->authorise($this->channel(), [
            'X-Trident-Controller-Token' => ControllerToken::generate()->value(),
        ])->assertOk();

        $this->assertSame('spectator', $this->userInfo($response)['role']);
    }

    public function test_a_malformed_token_does_not_explode(): void
    {
        $this->authorise($this->channel(), ['X-Trident-Controller-Token' => 'garbage'])->assertOk();
    }

    public function test_a_game_that_does_not_exist_is_refused(): void
    {
        $this->authorise('presence-game.'.GameId::random()->value())->assertForbidden();
    }

    public function test_a_finished_game_is_refused(): void
    {
        // A television left on all night does not stay subscribed to a dead game.
        $this->game->abandon(FrozenClock::at('2026-09-16 23:00:00'));
        $this->games->save($this->game);

        $this->authorise($this->channel())->assertForbidden();
    }

    public function test_a_channel_whose_id_is_not_a_game_is_refused(): void
    {
        $this->authorise('presence-game.not-a-uuid')->assertForbidden();
    }

    public function test_the_participant_id_travels_inside_the_payload(): void
    {
        // Echo drops the `user_id` key and only delivers `user_info`, so the
        // identifier has to go INSIDE the array or the UI never sees it.
        $info = $this->userInfo($this->authorise($this->channel()));

        $this->assertArrayHasKey('id', $info);
        $this->assertNotEmpty($info['id']);
    }

    public function test_the_response_never_carries_the_token_or_its_hash(): void
    {
        $response = $this->authorise($this->channel(), [
            'X-Trident-Controller-Token' => $this->token->value(),
        ])->assertOk();

        $body = $response->getContent() ?: '';

        $this->assertStringNotContainsString($this->token->value(), $body);
        $this->assertStringNotContainsString($this->game->controllerTokenHash(), $body);
    }
}
