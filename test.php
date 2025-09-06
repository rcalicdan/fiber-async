<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

echo "=== Background Email Sending Test ===\n";

class EmailService 
{
    private array $emailQueue = [];
    private string $logFile;
    private $logHandle;
    private bool $isProcessing = false;
    
    public function __construct()
    {
        $this->logFile = "email_service_" . date('Y-m-d_H-i-s') . ".log";
        $this->logHandle = fopen($this->logFile, 'w');
        
        // Register cleanup when process ends
        process_defer(function() {
            $this->cleanup();
        });
        
        $this->log("📧 Email service initialized");
    }
    
    public function queueEmail(string $to, string $subject, string $body): void
    {
        $email = [
            'id' => uniqid('email_'),
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s')
        ];
        
        $this->emailQueue[] = $email;
        $this->log("✉️  Email queued: {$email['id']} to {$to}");
    }
    
    public function processEmailsInBackground(): void
    {
        if ($this->isProcessing || empty($this->emailQueue)) {
            return;
        }
        
        $this->isProcessing = true;
        $this->log("🚀 Starting background email processing...");
        
        async(function() {
            foreach ($this->emailQueue as &$email) {
                if ($email['status'] !== 'queued') {
                    continue;
                }
                
                $this->log("📤 Sending email: {$email['id']}");
                $email['status'] = 'sending';
                $email['started_at'] = date('Y-m-d H:i:s');
                
                // Simulate email sending delay (2 seconds per email)
                await(delay(2.0));
                
                $email['status'] = 'sent';
                $email['sent_at'] = date('Y-m-d H:i:s');
                $this->log("✅ Email sent: {$email['id']} to {$email['to']}");
            }
            
            $this->log("🎉 All emails processed successfully!");
            $this->isProcessing = false;
        });
    }
    
    private function cleanup(): void
    {
        $this->log("🧹 EMAIL SERVICE CLEANUP STARTED");
        
        // Check for unsent emails
        $unsent = array_filter($this->emailQueue, fn($email) => $email['status'] !== 'sent');
        
        if (!empty($unsent)) {
            $this->log("⚠️  FOUND UNSENT EMAILS - SAVING TO RETRY QUEUE");
            
            // Save unsent emails to retry file
            $retryFile = "email_retry_queue_" . date('Y-m-d_H-i-s') . ".json";
            file_put_contents($retryFile, json_encode($unsent, JSON_PRETTY_PRINT));
            $this->log("💾 Saved " . count($unsent) . " unsent emails to: {$retryFile}");
            
            echo "📋 Unsent emails saved to retry queue: {$retryFile}\n";
        }
        
        // Generate final report
        $totalEmails = count($this->emailQueue);
        $sentEmails = count(array_filter($this->emailQueue, fn($email) => $email['status'] === 'sent'));
        $unsentEmails = $totalEmails - $sentEmails;
        
        $this->log("📊 FINAL STATISTICS:");
        $this->log("   - Total emails: {$totalEmails}");
        $this->log("   - Successfully sent: {$sentEmails}");
        $this->log("   - Unsent (will retry): {$unsentEmails}");
        
        // Close log file
        if (is_resource($this->logHandle)) {
            $this->log("📝 Closing log file: {$this->logFile}");
            fclose($this->logHandle);
        }
        
        echo "✅ Email service cleanup completed!\n";
        echo "📋 Check log file: {$this->logFile}\n";
    }
    
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}\n";
        
        // Write to log file
        if (is_resource($this->logHandle)) {
            fwrite($this->logHandle, $logEntry);
            fflush($this->logHandle);
        }
        
        // Also output to console
        echo $logEntry;
    }
    
    public function getStatus(): array
    {
        $total = count($this->emailQueue);
        $sent = count(array_filter($this->emailQueue, fn($email) => $email['status'] === 'sent'));
        $sending = count(array_filter($this->emailQueue, fn($email) => $email['status'] === 'sending'));
        $queued = count(array_filter($this->emailQueue, fn($email) => $email['status'] === 'queued'));
        
        return [
            'total' => $total,
            'sent' => $sent,
            'sending' => $sending,
            'queued' => $queued,
            'is_processing' => $this->isProcessing
        ];
    }
}

// Create email service
$emailService = new EmailService();

// Queue some emails
echo "📝 Queueing emails...\n";
$emailService->queueEmail('user1@example.com', 'Welcome!', 'Welcome to our service!');
$emailService->queueEmail('user2@example.com', 'Newsletter', 'Monthly newsletter content');
$emailService->queueEmail('user3@example.com', 'Reminder', 'Don\'t forget your appointment');
$emailService->queueEmail('admin@example.com', 'Daily Report', 'System status report');

// Start background processing
echo "\n🚀 Starting background email processing...\n";
$emailService->processEmailsInBackground();

// Simulate main application doing other work
echo "💻 Main application continuing with other tasks...\n";
for ($i = 1; $i <= 5; $i++) {
    echo "Main app task {$i}/5 - ";
    
    $status = $emailService->getStatus();
    echo "Emails: {$status['sent']}/{$status['total']} sent\n";
    
    sleep(1);
    
    // Simulate early termination after 3 seconds (before all emails are sent)
    if ($i === 3) {
        echo "\n💥 Simulating process interruption (Ctrl+C or crash)!\n";
        echo "⏰ Some emails are still being sent in background...\n";
        exit(0); // Force early exit - defer should handle cleanup
    }
}

echo "This line should not be reached due to early exit.\n";

// Run event loop (won't be reached due to early exit)
EventLoop::getInstance()->run();