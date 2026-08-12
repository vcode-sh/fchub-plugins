<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bootstrap.php';

abstract class InstalledContractTestCase extends TestCase
{
    private string $composeFile;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->composeFile = (string) getenv('CARTSHIFT_INTEGRATION_COMPOSE_FILE');
        $this->project = (string) getenv('CARTSHIFT_INTEGRATION_PROJECT');

        if ($this->composeFile === '' || $this->project === '') {
            self::markTestSkipped(
                'Installed contracts require tests/Integration/scripts/run-installed-contracts.sh.',
            );
        }

        self::assertFileExists($this->composeFile);
        self::assertMatchesRegularExpression('/\Acartshift-contract-[a-z0-9-]+\z/', $this->project);
    }

    /** @return array<string, mixed> */
    final protected function runRuntimeContract(string $case): array
    {
        return $this->runRuntimeContractWithArguments($case);
    }

    /** @param list<string> $arguments @return array<string, mixed> */
    final protected function runRuntimeContractWithArguments(string $case, array $arguments = []): array
    {
        self::assertMatchesRegularExpression('/\A[a-z0-9-]+\z/', $case);

        $command = $this->wpCliProcessCommand([
            'eval-file',
            '/cartshift-source/tests/Integration/Contract/runtime-contract.php',
            $case,
            ...$arguments,
        ]);

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process, 'Docker Compose contract process did not start.');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(
            0,
            $status,
            sprintf("Installed contract %s failed.\nSTDOUT:\n%s\nSTDERR:\n%s", $case, $stdout, $stderr),
        );

        self::assertIsString($stdout);
        $marker = 'CARTSHIFT_CONTRACT_JSON:';
        $position = strrpos($stdout, $marker);
        self::assertNotFalse($position, 'Installed contract emitted no canonical result marker.');

        $json = trim(substr($stdout, $position + strlen($marker)));
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($result);

        return $result;
    }

    /** @param list<string> $arguments @return array{status:int,stdout:string,stderr:string} */
    final protected function runWpCliCommand(array $arguments): array
    {
        $command = $this->wpCliProcessCommand($arguments);
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'WP-CLI contract process did not start.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    /** @param list<string> $arguments @return list<string> */
    final protected function wpCliProcessCommand(array $arguments): array
    {
        return [
            'docker', 'compose', '--project-name', $this->project, '--file', $this->composeFile,
            'exec', '-T', 'wpcli', 'wp', '--allow-root', ...$arguments,
        ];
    }
}
