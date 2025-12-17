<?php

namespace App\Service;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class PlaywrightService
{
    public function __construct(
        private string $nodeScriptsPath,
        private string $nodeExecutable,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Run Playwright audit on a URL
     *
     * @param string $url URL to audit
     * @param string $auditScope Scope of audit: 'full', 'transverse', or 'main_content'
     */
    public function runAudit(string $url, string $auditScope = 'full'): array
    {
        $scriptPath = $this->nodeScriptsPath . '/playwright-audit.js';

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException("Playwright script not found at: {$scriptPath}");
        }

        $process = new Process([
            $this->nodeExecutable,
            $scriptPath,
            $url,
            $auditScope
        ]);

        $process->setTimeout(300); // 5 minutes timeout

        try {
            $process->mustRun();
            $output = $process->getOutput();

            $this->logger->info('Playwright audit completed', [
                'url' => $url,
                'output_length' => strlen($output)
            ]);

            $result = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode Playwright output: ' . json_last_error_msg());
            }

            return $result;

        } catch (ProcessFailedException $exception) {
            $this->logger->error('Playwright audit failed', [
                'url' => $url,
                'error' => $exception->getMessage(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput()
            ]);

            throw new \RuntimeException('Playwright audit failed: ' . $exception->getMessage());
        }
    }

    /**
     * Check if Playwright is properly installed
     */
    public function checkInstallation(): bool
    {
        $scriptPath = $this->nodeScriptsPath . '/playwright-audit.js';
        return file_exists($scriptPath);
    }
}
