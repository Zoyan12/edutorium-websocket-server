<?php
/**
 * Standalone Battle WebSocket Server for Edutorium
 * 
 * This server manages battle matchmaking and gameplay
 */

// Load dependencies from client directory
require __DIR__ . '/../client/vendor/autoload.php';
require __DIR__ . '/config/config.php';

// Display startup diagnostics
logMessage('INFO', 'Starting Edutorium WebSocket Server...');
logMessage('INFO', 'Current directory: ' . getcwd());
logMessage('INFO', 'Environment: ' . APP_ENV);
logMessage('INFO', 'Supabase URL: ' . SUPABASE_URL);
logMessage('INFO', 'API Key length: ' . (strlen(SUPABASE_ANON_KEY) > 10 ? "OK (" . strlen(SUPABASE_ANON_KEY) . " chars)" : "MISSING"));

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

/**
 * Battle Server - WebSocket server for handling real-time educational battles
 */
class BattleServer implements MessageComponentInterface {
    protected $clients;
    protected $users = []; // userId => connection
    protected $userDetails = []; // userId => user details (name, avatar, etc)
    protected $waitingPlayers = []; // players waiting for a match
    protected $activeBattles = []; // ongoing battles
    protected $matchConfirmations = []; // players who have confirmed a match
    protected $battleState = []; // state of each battle
    protected $questionSets = []; // question sets for each battle

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        logMessage('INFO', 'Battle Server started!');
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->battleData = new \stdClass();
        $conn->battleData->userId = null;
        $conn->battleData->username = null;
        $conn->battleData->avatar = null;
        $conn->battleData->inBattle = false;
        $conn->battleData->battleId = null;
        $conn->battleData->state = 'connected';
        $conn->battleData->config = null;
        $conn->battleData->lastPing = time();
        
        logMessage('INFO', "New connection! ({$conn->resourceId})");
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['action'])) {
            return;
        }
        
        logMessage('DEBUG', "Received message from {$from->resourceId}: {$data['action']}");
        
        switch ($data['action']) {
            case 'login':
                $this->handleLogin($from, $data);
                break;
                
            case 'find_match':
                $this->handleFindMatch($from, $data);
                break;
                
            case 'cancel_matchmaking':
                $this->handleCancelMatchmaking($from);
                break;
                
            case 'confirm_match':
                $this->handleConfirmMatch($from, $data);
                break;
                
            case 'submit_answer':
                $this->handleSubmitAnswer($from, $data);
                break;
                
            case 'ready_for_next_round':
                $this->handleReadyForNextRound($from);
                break;
                
            case 'join_match':
                $this->handleJoinMatch($from, $data);
                break;
                
            case 'ping':
                $this->handlePing($from);
                break;
                
            case 'pong':
                $this->handlePong($from);
                break;
                
            default:
                logMessage('WARNING', "Unknown action: {$data['action']}");
        }
    }

    public function onClose(ConnectionInterface $conn) {
        logMessage('INFO', "Connection closing for resource {$conn->resourceId}");
        
        $this->clients->detach($conn);
        
        if ($conn->battleData->userId) {
            $userId = $conn->battleData->userId;
            logMessage('INFO', "Cleaning up user data for user: {$userId}");
            unset($this->users[$userId]);
            unset($this->userDetails[$userId]);
            
            // Remove from waiting players
            if (isset($this->waitingPlayers[$userId])) {
                unset($this->waitingPlayers[$userId]);
                logMessage('INFO', "Removed user {$userId} from waiting players");
            }
            
            // Handle battle disconnection
            if ($conn->battleData->inBattle && $conn->battleData->battleId) {
                logMessage('INFO', "Handling battle disconnection for user {$userId}");
                $this->handleBattleDisconnection($conn);
            }
        }
        
        logMessage('INFO', "Connection closed! ({$conn->resourceId})");
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        logMessage('ERROR', "WebSocket error for connection {$conn->resourceId}: {$e->getMessage()}");
        logMessage('ERROR', "Stack trace: " . $e->getTraceAsString());
        $conn->close();
    }

    private function handleLogin($conn, $data) {
        if (!isset($data['token'])) {
            $this->sendError($conn, 'Missing authentication token');
            return;
        }
        
        $token = $data['token'];
        
        // Verify token with Supabase
        $response = supabaseRequest('/auth/v1/user', 'GET', null, $token);
        
        logMessage('DEBUG', "Supabase response status: " . $response['status']);
        logMessage('DEBUG', "Supabase response data: " . json_encode($response['data']));
        
        if ($response['status'] !== 200) {
            logMessage('ERROR', "Token verification failed with status: " . $response['status']);
            $this->sendError($conn, 'Invalid authentication token');
            return;
        }
        
        $user = $response['data'];
        $userId = $user['id'];
        
        // Store user data
        $conn->battleData->userId = $userId;
        $conn->battleData->username = $user['user_metadata']['username'] ?? 'User' . substr($userId, 0, 8);
        $conn->battleData->avatar = $user['user_metadata']['avatar_url'] ?? null;
        
        $this->users[$userId] = $conn;
        $this->userDetails[$userId] = [
            'username' => $conn->battleData->username,
            'avatar' => $conn->battleData->avatar,
            'connected_at' => time()
        ];
        
        logMessage('INFO', "User logged in: {$conn->battleData->username} ({$userId})");
        
        $loginResponse = [
            'action' => 'login_success',
            'user' => [
                'id' => $userId,
                'username' => $conn->battleData->username,
                'avatar' => $conn->battleData->avatar
            ]
        ];
        
        logMessage('DEBUG', "Sending login success message: " . json_encode($loginResponse));
        $this->sendMessage($conn, $loginResponse);
        logMessage('DEBUG', "Login success message sent successfully");
    }

    private function handleFindMatch($conn, $data) {
        logMessage('DEBUG', "handleFindMatch called for connection {$conn->resourceId}");
        
        if (!$conn->battleData->userId) {
            logMessage('ERROR', "Find match request from unauthenticated connection {$conn->resourceId}");
            $this->sendError($conn, 'Not authenticated');
            return;
        }
        
        $userId = $conn->battleData->userId;
        
        // Store matchmaking configuration
        $conn->battleData->config = $data['config'] ?? [];
        
        logMessage('DEBUG', "User {$conn->battleData->username} config: " . json_encode($conn->battleData->config));
        
        // Add to waiting players
        $this->waitingPlayers[$userId] = [
            'connection' => $conn,
            'config' => $conn->battleData->config,
            'waiting_since' => time()
        ];
        
        logMessage('INFO', "User {$conn->battleData->username} started matchmaking");
        logMessage('DEBUG', "Current waiting players count: " . count($this->waitingPlayers));
        
        $this->sendMessage($conn, [
            'action' => 'matchmaking_started',
            'message' => 'Looking for opponents...'
        ]);
        
        // Try to find a match
        $this->tryMatchmaking();
    }

    private function handleCancelMatchmaking($conn) {
        if (!$conn->battleData->userId) {
            return;
        }
        
        $userId = $conn->battleData->userId;
        
        if (isset($this->waitingPlayers[$userId])) {
            unset($this->waitingPlayers[$userId]);
            logMessage('INFO', "User {$conn->battleData->username} cancelled matchmaking");
            
            $this->sendMessage($conn, [
                'action' => 'matchmaking_cancelled',
                'message' => 'Matchmaking cancelled'
            ]);
        }
    }

    private function handleConfirmMatch($conn, $data) {
        if (!$conn->battleData->userId) {
            return;
        }
        
        $userId = $conn->battleData->userId;
        $battleId = $data['battle_id'] ?? null;
        
        logMessage('DEBUG', "handleConfirmMatch called for user {$userId}, battle_id: {$battleId}");
        logMessage('DEBUG', "Active battles: " . json_encode(array_keys($this->activeBattles)));
        
        if (!$battleId || !isset($this->activeBattles[$battleId])) {
            logMessage('ERROR', "Invalid battle ID: {$battleId}, available battles: " . json_encode(array_keys($this->activeBattles)));
            $this->sendError($conn, 'Invalid battle ID');
            return;
        }
        
        $battle = $this->activeBattles[$battleId];
        
        if (!in_array($userId, [$battle['player1'], $battle['player2']])) {
            $this->sendError($conn, 'Not part of this battle');
            return;
        }
        
        $this->matchConfirmations[$battleId][$userId] = true;
        
        logMessage('INFO', "User {$conn->battleData->username} confirmed battle {$battleId}");
        
        // Notify the other player that this player is ready
        $player1Conn = $this->users[$battle['player1']];
        $player2Conn = $this->users[$battle['player2']];
        
        if ($userId === $battle['player1'] && !isset($this->matchConfirmations[$battleId][$battle['player2']])) {
            // Player 1 is ready, notify player 2
            $this->sendMessage($player2Conn, [
                'type' => 'opponentReady',
                'action' => 'opponent_ready'
            ]);
        } else if ($userId === $battle['player2'] && !isset($this->matchConfirmations[$battleId][$battle['player1']])) {
            // Player 2 is ready, notify player 1
            $this->sendMessage($player1Conn, [
                'type' => 'opponentReady',
                'action' => 'opponent_ready'
            ]);
        }
        
        // Check if both players confirmed
        if (count($this->matchConfirmations[$battleId]) === 2) {
            logMessage('INFO', "Both players ready for battle {$battleId}, sending bothReady message");
            
            // Send bothReady message to both players
            $this->sendMessage($player1Conn, [
                'type' => 'bothReady',
                'action' => 'both_ready',
                'battle_id' => $battleId
            ]);
            
            $this->sendMessage($player2Conn, [
                'type' => 'bothReady',
                'action' => 'both_ready',
                'battle_id' => $battleId
            ]);
            
            // Start battle after a 4-second delay (to allow for the countdown animation)
            $this->scheduleStartBattle($battleId, 4);
        }
    }
    
    private function scheduleStartBattle($battleId, $delay) {
        logMessage('INFO', "Scheduling battle {$battleId} to start in {$delay} seconds");
        
        // Use a timer to start the battle after the countdown
        // Note: This is a simplified approach. In production, you might want to use a proper timer/scheduler
        $loop = \React\EventLoop\Loop::get();
        $loop->addTimer($delay, function() use ($battleId) {
            if (isset($this->activeBattles[$battleId])) {
                logMessage('INFO', "Starting battle {$battleId} after countdown");
                $this->startBattle($battleId);
            }
        });
    }

    private function handleSubmitAnswer($conn, $data) {
        if (!$conn->battleData->userId || !$conn->battleData->inBattle) {
            return;
        }
        
        $userId = $conn->battleData->userId;
        $battleId = $conn->battleData->battleId;
        $answer = $data['answer'] ?? null;
        $timeSpent = $data['time_spent'] ?? 0;
        
        if (!isset($this->battleState[$battleId])) {
            return;
        }
        
        $battleState = &$this->battleState[$battleId];
        
        // Store the answer
        $battleState['answers'][$userId] = [
            'answer' => $answer,
            'time_spent' => $timeSpent,
            'submitted_at' => time()
        ];
        
        logMessage('DEBUG', "User {$conn->battleData->username} submitted answer for battle {$battleId}");
        
        // Check if both players answered
        if (count($battleState['answers']) === 2) {
            $this->processAnswers($battleId);
        }
    }

    private function handleReadyForNextRound($conn) {
        if (!$conn->battleData->userId || !$conn->battleData->inBattle) {
            return;
        }
        
        $userId = $conn->battleData->userId;
        $battleId = $conn->battleData->battleId;
        
        if (!isset($this->battleState[$battleId])) {
            return;
        }
        
        $battleState = &$this->battleState[$battleId];
        $battleState['ready_players'][$userId] = true;
        
        // Check if both players are ready
        if (count($battleState['ready_players']) === 2) {
            $this->nextRound($battleId);
        }
    }

    private function handleJoinMatch($conn, $data) {
        if (!$conn->battleData->userId) {
            logMessage('ERROR', 'Attempted join_match without login');
            $this->sendError($conn, 'Not logged in');
            return;
        }
        
        $userId = $conn->battleData->userId;
        $matchId = $data['matchId'] ?? null;
        
        logMessage('DEBUG', "handleJoinMatch called for user {$userId}, matchId: {$matchId}");
        
        if (!$matchId) {
            logMessage('ERROR', 'No matchId provided');
            $this->sendError($conn, 'Match ID is required');
            return;
        }
        
        if (!isset($this->activeBattles[$matchId])) {
            logMessage('ERROR', "Battle {$matchId} not found in active battles");
            $this->sendError($conn, 'Battle not found. Available battles: ' . json_encode(array_keys($this->activeBattles)));
            return;
        }
        
        $battle = $this->activeBattles[$matchId];
        
        // Verify the user is authorized to join this battle
        if ($battle['player1'] !== $userId && $battle['player2'] !== $userId) {
            logMessage('ERROR', "User {$userId} not authorized for battle {$matchId}");
            $this->sendError($conn, 'You are not authorized to join this battle');
            return;
        }
        
        // Update user's battle data
        $conn->battleData->battleId = $matchId;
        $conn->battleData->inBattle = true;
        $conn->battleData->state = 'in_battle';
        
        // Update connection reference
        $isPlayer1 = ($battle['player1'] === $userId);
        if ($isPlayer1) {
            $battle['player1_connection'] = $conn;
        } else {
            $battle['player2_connection'] = $conn;
        }
        $this->activeBattles[$matchId] = $battle;
        
        logMessage('INFO', "User {$conn->battleData->username} joined battle {$matchId}");
        
        // Send join success message
        $this->sendMessage($conn, [
            'action' => 'join_success',
            'type' => 'joinMatchSuccess',
            'battleId' => $matchId
        ]);
        
        // Send current battle state
        if (isset($this->battleState[$matchId])) {
            $currentState = $this->battleState[$matchId];
            
            // Resume battle if both players are back
            if ($battle['status'] === 'paused' && 
                isset($battle['player1_connection']) && 
                isset($battle['player2_connection'])) {
                $battle['status'] = 'active';
                $this->activeBattles[$matchId] = $battle;
                logMessage('INFO', "Battle {$matchId} resumed - both players reconnected");
            }
            
            // Send current question if battle is active
            if ($battle['status'] === 'active' && isset($currentState['questions'])) {
                $currentRound = $currentState['current_round'] ?? 1;
                $questionIndex = $currentRound - 1;
                
                if (isset($currentState['questions'][$questionIndex])) {
                    $question = $currentState['questions'][$questionIndex];
                    $this->sendMessage($conn, [
                        'action' => 'battle_started',
                        'type' => 'battleStart',
                        'battle_id' => $matchId,
                        'current_round' => $currentRound,
                        'total_rounds' => $currentState['total_rounds'],
                        'question' => $question,
                        'time_limit' => 30
                    ]);
                } else {
                    // Battle might be finished, send end state
                    $this->sendMessage($conn, [
                        'action' => 'battle_ended',
                        'final_scores' => [
                            'player1' => $currentState['player1_score'] ?? 0,
                            'player2' => $currentState['player2_score'] ?? 0
                        ]
                    ]);
                }
            } else {
                // Battle is paused, send waiting message
                $this->sendMessage($conn, [
                    'action' => 'battle_paused',
                    'message' => 'Waiting for opponent to reconnect...'
                ]);
            }
        }
    }

    private function handlePing($conn) {
        $conn->battleData->lastPing = time();
        $this->sendMessage($conn, ['action' => 'pong']);
    }
    
    private function handlePong($conn) {
        $conn->battleData->lastPing = time();
        logMessage('DEBUG', "Received pong from client {$conn->resourceId}");
    }

    private function tryMatchmaking() {
        if (count($this->waitingPlayers) < 2) {
            return;
        }
        
        $players = array_values($this->waitingPlayers);
        
        // Simple matchmaking - match first two players
        $player1 = $players[0];
        $player2 = $players[1];
        
        $battleId = uniqid('battle_');
        
        // Create battle
        $this->activeBattles[$battleId] = [
            'id' => $battleId,
            'player1' => $player1['connection']->battleData->userId,
            'player2' => $player2['connection']->battleData->userId,
            'config' => $player1['config'],
            'created_at' => time(),
            'status' => 'waiting_confirmation'
        ];
        
        // Remove from waiting
        unset($this->waitingPlayers[$player1['connection']->battleData->userId]);
        unset($this->waitingPlayers[$player2['connection']->battleData->userId]);
        
        // Send match found to both players
        $this->sendMessage($player1['connection'], [
            'action' => 'match_found',
            'battle_id' => $battleId,
            'opponent' => [
                'username' => $player2['connection']->battleData->username,
                'avatar' => $player2['connection']->battleData->avatar
            ]
        ]);
        
        $this->sendMessage($player2['connection'], [
            'action' => 'match_found',
            'battle_id' => $battleId,
            'opponent' => [
                'username' => $player1['connection']->battleData->username,
                'avatar' => $player1['connection']->battleData->avatar
            ]
        ]);
        
        logMessage('INFO', "Match found: {$player1['connection']->battleData->username} vs {$player2['connection']->battleData->username}");
    }

    private function startBattle($battleId) {
        $battle = $this->activeBattles[$battleId];
        
        // Initialize battle state
        $this->battleState[$battleId] = [
            'current_round' => 1,
            'total_rounds' => $battle['config']['question_count'] ?? 5,
            'player1_score' => 0,
            'player2_score' => 0,
            'answers' => [],
            'ready_players' => [],
            'questions' => $this->generateQuestions($battle['config'])
        ];
        
        // Set players as in battle
        $player1Conn = $this->users[$battle['player1']];
        $player2Conn = $this->users[$battle['player2']];
        
        $player1Conn->battleData->inBattle = true;
        $player1Conn->battleData->battleId = $battleId;
        $player2Conn->battleData->inBattle = true;
        $player2Conn->battleData->battleId = $battleId;
        
        $battle['status'] = 'active';
        
        // Send battle start to both players
        $this->sendBattleStart($battleId);
        
        logMessage('INFO', "Battle {$battleId} started");
    }

    private function sendBattleStart($battleId) {
        $battle = $this->activeBattles[$battleId];
        $battleState = $this->battleState[$battleId];
        
        $player1Conn = $this->users[$battle['player1']];
        $player2Conn = $this->users[$battle['player2']];
        
        $question = $battleState['questions'][0];
        
        $battleData = [
            'action' => 'battle_started',
            'battle_id' => $battleId,
            'current_round' => 1,
            'total_rounds' => $battleState['total_rounds'],
            'question' => $question,
            'time_limit' => 30
        ];
        
        $this->sendMessage($player1Conn, $battleData);
        $this->sendMessage($player2Conn, $battleData);
    }

    private function generateQuestions($config) {
        // Simple question generation - in production, fetch from database
        $questions = [];
        $subjects = $config['subjects'] ?? ['Math', 'Science'];
        $difficulty = $config['difficulty'] ?? 'medium';
        
        for ($i = 0; $i < ($config['question_count'] ?? 5); $i++) {
            $questions[] = [
                'id' => $i + 1,
                'question' => "Sample question " . ($i + 1) . "?",
                'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                'correct_answer' => 'A',
                'subject' => $subjects[array_rand($subjects)],
                'difficulty' => $difficulty
            ];
        }
        
        return $questions;
    }

    private function processAnswers($battleId) {
        $battle = $this->activeBattles[$battleId];
        $battleState = &$this->battleState[$battleId];
        
        $player1Id = $battle['player1'];
        $player2Id = $battle['player2'];
        
        $player1Answer = $battleState['answers'][$player1Id];
        $player2Answer = $battleState['answers'][$player2Id];
        
        $currentQuestion = $battleState['questions'][$battleState['current_round'] - 1];
        $correctAnswer = $currentQuestion['correct_answer'];
        
        // Calculate scores
        $player1Correct = $player1Answer['answer'] === $correctAnswer;
        $player2Correct = $player2Answer['answer'] === $correctAnswer;
        
        if ($player1Correct) $battleState['player1_score']++;
        if ($player2Correct) $battleState['player2_score']++;
        
        // Send results to both players
        $results = [
            'action' => 'round_results',
            'round' => $battleState['current_round'],
            'correct_answer' => $correctAnswer,
            'player1' => [
                'answer' => $player1Answer['answer'],
                'correct' => $player1Correct,
                'time_spent' => $player1Answer['time_spent']
            ],
            'player2' => [
                'answer' => $player2Answer['answer'],
                'correct' => $player2Correct,
                'time_spent' => $player2Answer['time_spent']
            ],
            'scores' => [
                'player1' => $battleState['player1_score'],
                'player2' => $battleState['player2_score']
            ]
        ];
        
        $this->sendMessage($this->users[$player1Id], $results);
        $this->sendMessage($this->users[$player2Id], $results);
        
        // Clear answers for next round
        $battleState['answers'] = [];
        $battleState['ready_players'] = [];
        
        // Check if battle is complete
        if ($battleState['current_round'] >= $battleState['total_rounds']) {
            $this->endBattle($battleId);
        }
    }

    private function nextRound($battleId) {
        $battleState = &$this->battleState[$battleId];
        $battleState['current_round']++;
        
        if ($battleState['current_round'] <= $battleState['total_rounds']) {
            $question = $battleState['questions'][$battleState['current_round'] - 1];
            
            $battle = $this->activeBattles[$battleId];
            $player1Conn = $this->users[$battle['player1']];
            $player2Conn = $this->users[$battle['player2']];
            
            $roundData = [
                'action' => 'next_round',
                'round' => $battleState['current_round'],
                'question' => $question,
                'time_limit' => 30
            ];
            
            $this->sendMessage($player1Conn, $roundData);
            $this->sendMessage($player2Conn, $roundData);
        }
    }

    private function endBattle($battleId) {
        $battle = $this->activeBattles[$battleId];
        $battleState = $this->battleState[$battleId];
        
        $player1Conn = $this->users[$battle['player1']];
        $player2Conn = $this->users[$battle['player2']];
        
        // Determine winner
        $winner = null;
        if ($battleState['player1_score'] > $battleState['player2_score']) {
            $winner = $battle['player1'];
        } elseif ($battleState['player2_score'] > $battleState['player1_score']) {
            $winner = $battle['player2'];
        }
        
        $finalResults = [
            'action' => 'battle_ended',
            'final_scores' => [
                'player1' => $battleState['player1_score'],
                'player2' => $battleState['player2_score']
            ],
            'winner' => $winner,
            'is_draw' => $winner === null
        ];
        
        $this->sendMessage($player1Conn, $finalResults);
        $this->sendMessage($player2Conn, $finalResults);
        
        // Clean up battle data
        $player1Conn->battleData->inBattle = false;
        $player1Conn->battleData->battleId = null;
        $player2Conn->battleData->inBattle = false;
        $player2Conn->battleData->battleId = null;
        
        unset($this->activeBattles[$battleId]);
        unset($this->battleState[$battleId]);
        unset($this->matchConfirmations[$battleId]);
        
        logMessage('INFO', "Battle {$battleId} ended. Winner: " . ($winner ? $this->userDetails[$winner]['username'] : 'Draw'));
    }

    private function handleBattleDisconnection($conn) {
        if (!$conn->battleData->inBattle || !$conn->battleData->battleId) {
            return;
        }
        
        $battleId = $conn->battleData->battleId;
        
        if (isset($this->activeBattles[$battleId])) {
            $battle = $this->activeBattles[$battleId];

            // Mark disconnecting player, keep battle for rejoin
            if ($conn->battleData->userId === $battle['player1']) {
                $battle['player1_connection'] = null;
                $battle['player1_disconnected_at'] = time();
            } else if ($conn->battleData->userId === $battle['player2']) {
                $battle['player2_connection'] = null;
                $battle['player2_disconnected_at'] = time();
            }

            // Pause battle to allow rejoin
            $battle['status'] = 'paused';
            $this->activeBattles[$battleId] = $battle;
            
            // Notify opponent
            $opponentId = ($conn->battleData->userId === $battle['player1']) ? $battle['player2'] : $battle['player1'];
            if (isset($this->users[$opponentId])) {
                $this->sendMessage($this->users[$opponentId], [
                    'action' => 'opponent_disconnected',
                    'message' => 'Your opponent has disconnected'
                ]);
            }
            
            logMessage('WARNING', "Battle {$battleId} paused due to disconnection; awaiting possible rejoin");
        }
    }


    private function sendMessage($conn, $data) {
        try {
            $jsonData = json_encode($data);
            logMessage('DEBUG', "Sending message to connection {$conn->resourceId}: " . $jsonData);
            $conn->send($jsonData);
            logMessage('DEBUG', "Message sent successfully to connection {$conn->resourceId}");
        } catch (Exception $e) {
            logMessage('ERROR', "Failed to send message to connection {$conn->resourceId}: " . $e->getMessage());
        }
    }

    private function sendError($conn, $message) {
        $this->sendMessage($conn, [
            'action' => 'error',
            'message' => $message
        ]);
    }
    
    public function getClientCount() {
        return count($this->clients);
    }
    
    public function getClients() {
        return $this->clients;
    }
}

// Create and start the server
logMessage('INFO', 'Starting Battle WebSocket Server on port ' . WEBSOCKET_PORT . '...');

// Create BattleServer instance
$battleServer = new BattleServer();

// Create the IoServer
try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer($battleServer)
        ),
        WEBSOCKET_PORT,
        WEBSOCKET_HOST
    );
    logMessage('INFO', 'Server created successfully');
} catch (Exception $e) {
    logMessage('ERROR', 'Failed to create server: ' . $e->getMessage());
    exit(1);
}

logMessage('INFO', 'Battle WebSocket Server is running on port ' . WEBSOCKET_PORT . '. Press Ctrl+C to stop.');

// Start a simple HTTP server for health checks on port 8080
$httpServer = new \React\Http\HttpServer(function (\Psr\Http\Message\ServerRequestInterface $request) use ($battleServer) {
    $path = $request->getUri()->getPath();
    
    if ($path === '/health') {
        return new \React\Http\Message\Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'status' => 'healthy',
                'timestamp' => time(),
                'connections' => $battleServer->getClientCount()
            ])
        );
    }
    
    return new \React\Http\Message\Response(
        200,
        ['Content-Type' => 'application/json'],
        json_encode([
            'message' => 'Edutorium WebSocket Server',
            'status' => 'running',
            'websocket_url' => 'wss://edutorium-api.pegioncloud.com'
        ])
    );
});

$httpSocket = new \React\Socket\SocketServer('0.0.0.0:8080');
$httpServer->listen($httpSocket);
logMessage('INFO', 'HTTP health check server started on port 8080');

// Set up heartbeat after server is created
try {
    $loop = \React\EventLoop\Loop::get();
    $loop->addPeriodicTimer(10, function() use ($battleServer) {
        $currentTime = time();
        
        foreach ($battleServer->getClients() as $client) {
            // Send ping to client
            try {
                logMessage('DEBUG', "Sending ping to client {$client->resourceId}");
                $client->send(json_encode(['action' => 'ping']));
                logMessage('DEBUG', "Ping sent successfully to client {$client->resourceId}");
            } catch (Exception $e) {
                logMessage('ERROR', "Failed to send ping to client {$client->resourceId}: " . $e->getMessage());
            }
            
            // Check if client has responded recently
            if ($client->battleData->lastPing < $currentTime - 30) {
                logMessage('WARNING', "Client {$client->resourceId} timed out");
                $client->close();
            }
        }
        
        // Clean up paused battles that have been inactive for too long (2 minutes)
        $battlesToCleanup = [];
        foreach ($battleServer->activeBattles as $battleId => $battle) {
            if ($battle['status'] === 'paused') {
                $pausedTime = max(
                    $battle['player1_disconnected_at'] ?? 0,
                    $battle['player2_disconnected_at'] ?? 0
                );
                
                if ($pausedTime > 0 && $currentTime - $pausedTime > 120) { // 2 minutes
                    $battlesToCleanup[] = $battleId;
                }
            }
        }
        
        foreach ($battlesToCleanup as $battleId) {
            logMessage('INFO', "Cleaning up paused battle {$battleId} after 2 minutes");
            unset($battleServer->activeBattles[$battleId]);
            if (isset($battleServer->battleState[$battleId])) {
                unset($battleServer->battleState[$battleId]);
            }
        }
    });
    
    logMessage('INFO', 'Heartbeat timer set up successfully');
} catch (Exception $e) {
    logMessage('WARNING', 'Failed to set up heartbeat: ' . $e->getMessage());
}

$server->run();
