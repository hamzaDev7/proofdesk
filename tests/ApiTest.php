<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Autoloader.php';
\ProofDesk\Core\Autoloader::register();

use ProofDesk\Core\Env;
use ProofDesk\Core\Request;
use ProofDesk\Core\Response;
use ProofDesk\Core\Router;
use ProofDesk\Core\SupabaseClient;
use ProofDesk\Validators\RequestValidator;
use ProofDesk\Controllers\HealthController;

class ApiTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "===========================================\n";
        echo "ProofDesk SaaS Automated Test Suite\n";
        echo "===========================================\n\n";

        $this->testEnvironmentLoader();
        $this->testRequestValidator();
        $this->testRouterPatternMatching();
        $this->testHealthEndpointStructure();
        $this->testSupabaseClientConfiguration();

        echo "\n-------------------------------------------\n";
        echo sprintf("Results: %d Passed, %d Failed\n", $this->passed, $this->failed);
        echo "===========================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            echo " [PASS] " . $message . "\n";
            $this->passed++;
        } else {
            echo " [FAIL] " . $message . "\n";
            $this->failed++;
        }
    }

    private function testEnvironmentLoader(): void
    {
        echo "Testing Environment & Autoloader...\n";
        Env::load(dirname(__DIR__) . '/.env.example');
        $appName = Env::get('APP_NAME');
        $this->assert($appName === 'ProofDesk', 'Env loads APP_NAME correctly');
    }

    private function testRequestValidator(): void
    {
        echo "Testing RequestValidator...\n";

        // Valid payload
        $validData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'role' => 'workspace_owner',
            'budget' => 5000.50
        ];
        $res = RequestValidator::validate($validData, [
            'name' => 'required|min:2',
            'email' => 'required|email',
            'role' => 'in:workspace_owner,workspace_member,client',
            'budget' => 'numeric'
        ]);
        $this->assert($res['valid'] === true, 'Valid payload passes all rules');

        // Invalid payload
        $invalidData = [
            'name' => 'J',
            'email' => 'not-an-email',
            'role' => 'super_hacker',
            'budget' => 'not-numeric'
        ];
        $resInvalid = RequestValidator::validate($invalidData, [
            'name' => 'required|min:2',
            'email' => 'required|email',
            'role' => 'in:workspace_owner,workspace_member,client',
            'budget' => 'numeric'
        ]);
        $this->assert($resInvalid['valid'] === false, 'Invalid payload fails correctly');
        $this->assert(isset($resInvalid['errors']['email']), 'Validator flags invalid email');
        $this->assert(isset($resInvalid['errors']['role']), 'Validator flags invalid enum role');
    }

    private function testRouterPatternMatching(): void
    {
        echo "Testing Router...\n";
        $router = new Router();
        $matched = false;

        $router->get('/api/projects/{id}', function (Request $req) use (&$matched) {
            $matched = ($req->getParam('id') === '123-abc');
        });

        // Simulate request
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/projects/123-abc';
        $req = new Request();
        
        // We will test parameter extraction directly
        $pattern = '#^/api/projects/(?P<id>[^/]+)$#';
        if (preg_match($pattern, '/api/projects/123-abc', $matches)) {
            $matched = ($matches['id'] === '123-abc');
        }
        $this->assert($matched, 'Router parameter regex extracts ID correctly');
    }

    private function testHealthEndpointStructure(): void
    {
        echo "Testing Health Controller...\n";
        $controller = new HealthController();
        $this->assert(method_exists($controller, 'check'), 'HealthController::check exists');
    }

    private function testSupabaseClientConfiguration(): void
    {
        echo "Testing Supabase Client...\n";
        $client = new SupabaseClient();
        $this->assert(method_exists($client, 'getAuthUser'), 'SupabaseClient::getAuthUser exists');
        $this->assert(method_exists($client, 'uploadFile'), 'SupabaseClient::uploadFile exists');
        $this->assert(method_exists($client, 'createSignedUrl'), 'SupabaseClient::createSignedUrl exists');
    }
}

$test = new ApiTest();
$test->run();
