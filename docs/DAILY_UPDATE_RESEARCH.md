# Daily Update Strategy Research: Cron Jobs vs Queue-Based Task Dispatching

## Executive Summary

This document analyzes two approaches for implementing daily updates in Simple-Trader:
1. **Standard Cron Jobs** - Traditional time-based task scheduling
2. **Queue-Based Task Dispatching** - Modern job queue with background workers

Based on the current architecture, project scope, and requirements, this research provides a recommendation.

---

## Current State Analysis

### Existing Infrastructure
- **No built-in scheduler** - Users manually configure OS-level cron
- **Simple background execution** - Uses `exec()` for spawning processes
- **Direct script invocation** - `investor.php` and CLI commands
- **No queue library** - No Laravel Queue, RabbitMQ, Beanstalk, etc.
- **SQLite databases** - Lightweight, file-based storage
- **Docker-ready** - Single service container

### Current Workflow
```
Cron/Manual → investor.php → Update Sources → Execute Strategies → Notify
```

### Tasks Requiring Daily Updates

1. **Ticker Quote Updates**
   - Fetch latest OHLCV data for all enabled tickers
   - Check for missing dates and fill gaps
   - Execute: Daily after market close (~4-6 PM)
   - Duration: ~30-60 seconds per ticker (API rate limits)
   - Frequency: Once per day

2. **Monitor Updates**
   - Process new day's data for active monitors
   - Load previous state from database
   - Execute strategy for new bar
   - Save new positions, trades, snapshots
   - Calculate updated metrics
   - Execute: After quote updates complete
   - Duration: ~5-30 seconds per monitor
   - Frequency: Once per day

3. **Monitor Backtest Initialization** (Already Implemented)
   - Run historical backtest when monitor created
   - Background process with progress tracking
   - Duration: Variable (minutes to hours depending on date range)
   - Frequency: On-demand (user triggered)

---

## Approach 1: Standard Cron Jobs

### Architecture

```
┌─────────────────┐
│   OS Cron Tab   │
└────────┬────────┘
         │
         ├──> 4:00 PM: php commands/update-quotes.php
         │              (Updates all enabled tickers)
         │
         ├──> 4:30 PM: php commands/update-monitors.php
         │              (Process active monitors)
         │
         └──> 5:00 PM: php commands/cleanup.php
                        (Optional: Archive old data)
```

### Implementation Details

**1. New CLI Commands to Create:**

```bash
# Update all enabled tickers
php commands/update-quotes.php [--ticker-id=X] [--force]

# Update all active monitors
php commands/update-monitors.php [--monitor-id=X] [--date=YYYY-MM-DD]

# Send daily summary email
php commands/send-daily-report.php
```

**2. Cron Configuration Example:**

```cron
# Update quotes at 4:15 PM ET (after market close)
15 16 * * 1-5 cd /var/www/simple-trader && php commands/update-quotes.php >> /var/log/quotes.log 2>&1

# Update monitors at 4:45 PM ET (after quotes finish)
45 16 * * 1-5 cd /var/www/simple-trader && php commands/update-monitors.php >> /var/log/monitors.log 2>&1

# Send daily summary at 5:00 PM ET
0 17 * * 1-5 cd /var/www/simple-trader && php commands/send-daily-report.php >> /var/log/reports.log 2>&1
```

**3. Error Handling:**

```php
// Each command handles its own errors
try {
    $result = $service->updateQuotes();
    logSuccess($result);
} catch (Exception $e) {
    logError($e);
    sendAlertEmail($e);
    exit(1); // Exit code for monitoring
}
```

### Advantages

✅ **Simple & Proven** - Battle-tested approach used by millions
✅ **No New Dependencies** - Uses OS-level features
✅ **Low Resource Usage** - Only runs when scheduled
✅ **Easy to Debug** - Direct execution, clear logs
✅ **Familiar** - Every developer knows cron
✅ **Predictable** - Runs at exact times
✅ **Isolated Execution** - Each job runs independently
✅ **Free** - No additional infrastructure costs
✅ **Works in Docker** - Can use cron in container or host
✅ **Perfect for Daily Tasks** - Designed for time-based execution

### Disadvantages

❌ **No Built-in Retry** - Failed jobs require manual intervention
❌ **Time Coupling** - Tasks tied to specific times, not dependencies
❌ **Limited Concurrency** - Can't easily parallelize
❌ **No Priority System** - All jobs equal priority
❌ **Basic Error Recovery** - Must implement yourself
❌ **Log Management** - Separate log files to maintain
❌ **Server-Specific** - Cron config not in version control (unless dockerized)
❌ **No Status Dashboard** - Can't see job status in UI without building it

### Best Practices for Cron Approach

1. **Staggered Scheduling** - Space out tasks to avoid resource spikes
2. **Atomic Operations** - Each task should be complete and independent
3. **Idempotent Design** - Safe to run multiple times with same result
4. **Comprehensive Logging** - Log everything for debugging
5. **Exit Codes** - Use proper codes for monitoring systems
6. **Locking Mechanism** - Prevent concurrent runs of same job
7. **Notification on Failure** - Alert when jobs fail
8. **Monitoring Integration** - Use tools like Cronitor, Healthchecks.io

### Cron Implementation Example

```php
// commands/update-quotes.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Services\QuoteUpdateService;

// Lock file to prevent concurrent execution
$lockFile = __DIR__ . '/../var/update-quotes.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another instance is running\n";
    exit(1);
}

try {
    $service = new QuoteUpdateService($tickerRepo, $quoteRepo);
    $results = $service->updateAllTickers();

    echo "✓ Updated {$results['success']} tickers\n";
    if ($results['failed'] > 0) {
        echo "✗ Failed: {$results['failed']} tickers\n";
        sendAlertEmail($results['errors']);
        exit(1);
    }
    exit(0);

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    sendAlertEmail($e);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
```

---

## Approach 2: Queue-Based Task Dispatching

### Architecture

```
┌──────────────┐         ┌──────────────┐         ┌──────────────┐
│   Scheduler  │────────>│  Job Queue   │────────>│   Workers    │
│   (Cron)     │         │  (Database)  │         │ (Background) │
└──────────────┘         └──────────────┘         └──────────────┘
                                 │
                                 ├─> Update Ticker 1
                                 ├─> Update Ticker 2
                                 ├─> Update Monitor 1
                                 ├─> Update Monitor 2
                                 └─> Send Report
```

### Implementation Details

**1. Queue Library Options:**

**Option A: PHP Queue (Simple)**
```bash
composer require php-queue/php-queue
```
- Lightweight (~10KB)
- Database-backed (can use SQLite)
- No external services needed
- Basic retry logic

**Option B: Bernard (Medium)**
```bash
composer require bernard/bernard
```
- Database or Redis backend
- Better retry and failure handling
- More robust than php-queue
- Good documentation

**Option C: Laravel Queue (Feature-Rich)**
```bash
composer require illuminate/queue
composer require illuminate/bus
```
- Industry standard
- Excellent documentation
- Rich features (delays, retries, priorities, batches)
- Can use database driver (no Redis required)
- Well-maintained

**2. Database Schema for Queue:**

```sql
-- jobs table
CREATE TABLE jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER DEFAULT 0,
    reserved_at INTEGER,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);

-- failed_jobs table
CREATE TABLE failed_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT,
    failed_at INTEGER NOT NULL
);
```

**3. Worker Process:**

```bash
# Long-running worker process
php commands/queue-worker.php --queue=default --sleep=3 --tries=3

# Supervisor configuration to keep worker alive
[program:simple-trader-worker]
command=php /var/www/simple-trader/commands/queue-worker.php
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/simple-trader-worker.log
```

**4. Job Dispatching:**

```php
// Dispatch jobs from cron or web UI
$queue->push(new UpdateTickerQuotesJob($tickerId));
$queue->push(new UpdateMonitorJob($monitorId), ['priority' => 'high']);
$queue->push(new SendDailyReportJob(), ['delay' => 3600]); // 1 hour delay
```

### Advantages

✅ **Automatic Retry** - Jobs retry on failure with exponential backoff
✅ **Concurrency** - Multiple workers can process jobs in parallel
✅ **Priority System** - Important jobs can jump the queue
✅ **Delayed Execution** - Schedule jobs for future execution
✅ **Job Batching** - Group related jobs together
✅ **Failure Tracking** - Failed jobs stored for review
✅ **Rate Limiting** - Control job execution rate per queue
✅ **Web Dashboard** - Can build UI to monitor queue status
✅ **Dependency Management** - Jobs can wait for other jobs to complete
✅ **Scalability** - Easy to add more workers as load increases
✅ **Flexibility** - Can trigger jobs from anywhere (cron, web, CLI)

### Disadvantages

❌ **Complexity** - More moving parts to maintain
❌ **New Dependencies** - Requires queue library
❌ **Worker Management** - Need process supervisor (systemd, supervisor)
❌ **Resource Usage** - Workers run continuously, consuming memory
❌ **Learning Curve** - Team needs to understand queue concepts
❌ **Debugging** - Async execution harder to trace
❌ **Database Load** - Frequent polling can stress database
❌ **Overkill for Simple Tasks** - Daily jobs don't need queue complexity
❌ **Extra Infrastructure** - Supervisor/systemd setup required
❌ **Monitoring Complexity** - Need to watch worker health

### Queue Implementation Example

```php
// config/queue.php
return [
    'default' => 'database',
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ],
];

// src/Jobs/UpdateTickerQuotesJob.php
class UpdateTickerQuotesJob
{
    private int $tickerId;

    public function handle(QuoteFetcher $fetcher): void
    {
        $fetcher->fetchQuotes($this->tickerId);
    }

    public function failed(\Exception $e): void
    {
        // Handle job failure
        Log::error("Failed to update ticker {$this->tickerId}: " . $e->getMessage());
        sendAlertEmail($e);
    }
}

// Dispatch from cron or web
Queue::push(new UpdateTickerQuotesJob($tickerId));
```

---

## Hybrid Approach (Recommended)

### Architecture

```
┌─────────────┐
│  Cron Job   │ (4:00 PM daily)
└──────┬──────┘
       │
       v
┌──────────────────────────────┐
│  Master Dispatcher Command   │
│  php commands/daily-update.php │
└──────┬───────────────────────┘
       │
       ├─> Sequential: Update all ticker quotes
       │   (Wait for completion)
       │
       ├─> Parallel: Spawn background workers for monitors
       │   php commands/update-monitor.php {id} &
       │
       └─> Send summary report when all complete
```

### Why Hybrid?

✅ **Best of Both Worlds** - Simple cron scheduling + parallel execution
✅ **No Queue Dependencies** - Uses existing background execution pattern
✅ **Predictable** - Runs at exact time
✅ **Scalable** - Can parallelize monitor updates
✅ **Simple** - Easy to understand and maintain
✅ **Fits Current Architecture** - Extends existing patterns

### Implementation Strategy

**1. Master Cron Job:**
```cron
# Single cron entry
15 16 * * 1-5 cd /var/www/simple-trader && php commands/daily-update.php
```

**2. Master Dispatcher Logic:**
```php
// commands/daily-update.php

// Phase 1: Update all ticker quotes (sequential, must complete first)
$quoteService->updateAllTickers();

// Phase 2: Get all active monitors
$activeMonitors = $monitorRepo->getActiveMonitors();

// Phase 3: Spawn parallel background workers for each monitor
foreach ($activeMonitors as $monitor) {
    $this->spawnBackgroundWorker($monitor['id']);
}

// Phase 4: Wait for all monitors to complete (optional)
$this->waitForCompletion($activeMonitors);

// Phase 5: Send daily summary
$this->sendDailySummary();
```

---

## Comparison Matrix

| Feature | Cron Jobs | Queue System | Hybrid |
|---------|-----------|--------------|--------|
| **Setup Complexity** | Low | High | Low-Medium |
| **Dependencies** | None | Queue lib + Supervisor | None |
| **Resource Usage** | Low (on-demand) | High (always on) | Low-Medium |
| **Retry Logic** | Manual | Automatic | Manual |
| **Parallel Execution** | Limited | Excellent | Good |
| **Error Handling** | Custom | Built-in | Custom |
| **Debugging** | Easy | Moderate | Easy |
| **Scalability** | Limited | Excellent | Good |
| **Maintenance** | Low | Medium-High | Low |
| **Monitoring** | Basic | Advanced | Basic |
| **Fit with Current** | Excellent | Requires refactor | Excellent |
| **Time to Implement** | 1-2 days | 5-7 days | 2-3 days |

---

## Specific Considerations for Simple-Trader

### Current Usage Patterns

1. **Ticker Updates**
   - Low frequency (once per day)
   - Sequential execution acceptable
   - API rate limits prevent parallelization anyway
   - Predictable timing required (after market close)

2. **Monitor Updates**
   - Potentially dozens of monitors
   - Can be parallelized effectively
   - Independent of each other
   - Don't require exact timing

3. **User Expectations**
   - Users expect updates at consistent times
   - Dashboard should show "last updated" times
   - Failures should be visible and actionable

### Technical Constraints

- **SQLite Database** - May not handle high concurrent writes from queue
- **Shared Hosting Friendly** - Cron works on most hosts
- **Docker Deployment** - Cron can run in container or host
- **Team Size** - Smaller teams benefit from simplicity
- **Current Patterns** - Already using background execution for backtests

---

## Recommendations

### Immediate Term: Standard Cron Jobs (Phase 1)

**Reasoning:**
1. Matches current architecture perfectly
2. No new dependencies
3. Simple to implement and maintain
4. Sufficient for current scale
5. Can be implemented in 1-2 days

**Implementation:**
- Create `commands/daily-update.php` master script
- Update all tickers sequentially
- Spawn background workers for monitors
- Log everything comprehensively
- Send email notifications on failures

### Future Enhancement: Queue System (Phase 2 - Optional)

**When to Consider:**
- More than 50 monitors running daily
- Need for priority queuing
- Require advanced retry logic
- Want web-based job monitoring
- Multiple types of background tasks added

**Recommended Library:**
- **Laravel Queue** with database driver
  - Familiar to most PHP developers
  - Excellent documentation
  - No Redis required (can use SQLite)
  - Easy to add later without major refactor

---

## Implementation Roadmap (Recommended)

### Phase 1: Cron-Based Daily Updates (Week 1)

**Day 1-2: Ticker Quote Updates**
```bash
commands/update-quotes.php
- Fetch quotes for all enabled tickers
- Smart gap filling
- Error handling and logging
- Email notifications
```

**Day 3-4: Monitor Updates**
```bash
commands/update-monitors.php
- Load previous state
- Process new day
- Save snapshots/trades
- Calculate metrics
- Parallel execution
```

**Day 5: Master Dispatcher**
```bash
commands/daily-update.php
- Orchestrate all updates
- Wait for completion
- Generate summary report
- Handle failures gracefully
```

### Phase 2: Monitoring & Observability (Week 2)

- Add status tracking table
- Build admin dashboard for job status
- Integrate with Healthchecks.io or Cronitor
- Set up alerting for failures

### Phase 3: Queue System (Future - If Needed)

- Add Laravel Queue package
- Refactor update commands to jobs
- Set up worker supervisor
- Build queue dashboard

---

## Code Examples

### Recommended Approach: Cron + Parallel Execution

```php
// commands/daily-update.php
class DailyUpdateCommand
{
    public function execute(): void
    {
        $this->log("=== Daily Update Started ===");

        // Phase 1: Update quotes (sequential - required)
        $this->updateQuotes();

        // Phase 2: Update monitors (parallel - optional)
        $this->updateMonitors();

        // Phase 3: Send summary
        $this->sendSummary();

        $this->log("=== Daily Update Completed ===");
    }

    private function updateQuotes(): void
    {
        $tickers = $this->tickerRepo->getEnabledTickers();

        foreach ($tickers as $ticker) {
            try {
                $this->quoteFetcher->fetchQuotes($ticker['id']);
                $this->log("✓ Updated {$ticker['symbol']}");
            } catch (\Exception $e) {
                $this->logError("✗ Failed {$ticker['symbol']}: " . $e->getMessage());
            }
        }
    }

    private function updateMonitors(): void
    {
        $monitors = $this->monitorRepo->getMonitorsByStatus('active');
        $workers = [];

        foreach ($monitors as $monitor) {
            // Spawn background worker
            $pid = $this->spawnWorker($monitor['id']);
            $workers[] = ['id' => $monitor['id'], 'pid' => $pid];
        }

        // Optionally wait for completion
        $this->waitForWorkers($workers);
    }

    private function spawnWorker(int $monitorId): int
    {
        $command = sprintf(
            'php %s/commands/update-monitor.php %d > /dev/null 2>&1 &',
            $this->projectRoot,
            $monitorId
        );

        exec($command . ' echo $!', $output);
        return (int)$output[0]; // Return PID
    }
}
```

---

## Conclusion

**Recommendation: Start with Standard Cron Jobs**

The cron-based approach is the clear winner for Simple-Trader because:

1. **Perfect Fit** - Aligns with current architecture and patterns
2. **Low Risk** - Proven technology with no new dependencies
3. **Fast Implementation** - Can be done in 1-2 days
4. **Sufficient Scale** - Handles dozens of monitors easily
5. **Easy Maintenance** - Simple to debug and monitor
6. **Cost Effective** - No additional infrastructure
7. **Flexible** - Can add queue system later if needed

The hybrid approach (cron + parallel background workers) gives you the benefits of scheduled execution with the performance of parallel processing, without the complexity of a full queue system.

**Queue system should only be considered if:**
- You have >100 monitors updating daily
- You need sub-minute job scheduling
- You require complex job dependencies
- You want advanced monitoring dashboards
- You have dedicated DevOps resources

For now, **implement Phase 1 (Cron-Based)** and reassess after 3-6 months of production usage.
