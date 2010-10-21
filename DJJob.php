<?php

# This system is mostly a port of delayed_job: http://github.com/tobi/delayed_job
# though with slightly different semantics because DJ is based on Active Record
# and I didn't want to tie this into Propel

class DJBase {
    protected static function runQuery($sql, $params = array()) {
        $con = Propel::getConnection();
        $stmt = $con->prepareStatement($sql);
        
        $i = 0;
        foreach ($params as $p)
            $stmt->set(++$i, $p);
        
        return $stmt->executeQuery();
    }
    
    protected static function runUpdate($sql, $params = array()) {
        $con = Propel::getConnection();
        $stmt = $con->prepareStatement($sql);
        
        $i = 0;
        foreach ($params as $p)
            $stmt->set(++$i, $p);
        
        return $stmt->executeUpdate();
    }
    
    protected static function log($mesg) {
        echo $mesg . "\n";
    }
}

class DJRetryException extends Exception { }

class DJWorker extends DJBase {
    # This is a singleton-ish thing. It wouldn't really make sense to
    # instantiate more than one in a single request (or commandline task)
    
    public function __construct($options) {
        $options = array_merge(array(
            "queue" => "default",
            "count" => 0,
            "sleep" => 5
        ), $options);
        list($this->queue, $this->count, $this->sleep) = array($options["queue"], $options["count"], $options["sleep"]);
        list($hostname, $pid) = array(trim(`hostname`), getmypid());
        $this->name = "host::$hostname pid::$pid";
        
        if (function_exists("pcntl_signal")) {
            pcntl_signal(SIGTERM, array($this, "handleSignal"));
            pcntl_signal(SIGINT, array($this, "handleSignal"));
        }
    }
    
    public function handleSignal($signo) {
        $signals = array(
            SIGTERM => "SIGTERM",
            SIGINT  => "SIGINT"
        );
        $signal = $signals[$signo];
        
        $this->log("* [WORKER] Received received {$signal}... Shutting down");
        $this->releaseLocks();
        die(0);
    }
    
    public function releaseLocks() {
        $this->runUpdate("
            UPDATE jobs
            SET locked_at = NULL, locked_by = NULL
            WHERE locked_by = ?",
            array($this->name)
        );
    }
    
    public function getNewJob() {
        # we can grab a locked job if we own the lock
        $rs = $this->runQuery("
            SELECT id
            FROM   jobs
            WHERE  queue = ?
            AND    (run_at IS NULL OR NOW() >= run_at)
            AND    (locked_at IS NULL OR locked_by = ?) AND failed_at IS NULL
            ORDER BY RAND()
            LIMIT  5
        ", array($this->queue, $this->name));
        
        foreach ($rs as $r) {
            $job = new DJJob($this->name, $rs->getInt("id"));
            if ($job->acquireLock()) return $job;
        }
        
        return false;
    }
    
    public function start() {
        $this->log("* [JOB] Starting worker {$this->name} on queue::{$this->queue}");
        
        $count = 0;
        try {
            while ($this->count == 0 or $this->count > $count) {
                if (function_exists("pcntl_signal_dispatch")) pcntl_signal_dispatch();
                
                $job = $this->getNewJob($this->queue);

                if (!$job) {
                    $this->log("* [JOB] Failed to get a job, queue::{$this->queue} may be empty");
                    sleep($this->sleep);
                    continue;
                }

                $job->run();
                $count += 1;
            }
        } catch (Exception $e) {
            $this->log("* [JOB] unhandled exception::\"{$e->getMessage()}\"");
        }

        $this->log("* [JOB] worker shutting down after running {$count} jobs");
    }
}

class DJJob extends DJBase {
    
    public function __construct($worker_name, $job_id) {
        $this->worker_name = $worker_name;
        $this->job_id = $job_id;
    }
    
    public function run() {
        # pull the handler from the db
        $handler = $this->getHandler();
        if (!is_object($handler)) {
            $this->log("* [JOB] bad handler for job::{$this->job_id}");
            $this->finishWithError("bad handler for job::{$this->job_id}");
            return false;
        }
        
        # run the handler
        try {
            $handler->perform();
            
            # cleanup
            $this->finish();
            return true;
            
        } catch (DJRetryException $e) {
          
            # signal that this job should be retried later
            $this->retryLater();
            return false;
            
        } catch (Exception $e) {
          
            # don't handle this exception, crash the worker and force a new DB connection
            if(($e instanceof SQLException or $e instanceof PropelException)
                                    && preg_match('/gone away/', $e->getMessage())) {
                $this->releaseLock();
                throw $e;
            }
            
            $this->finishWithError($e->getMessage());
            return false;
            
        }
    }
    
    public function acquireLock() {
        $this->log("* [JOB] attempting to acquire lock for job::{$this->job_id} on {$this->worker_name}");
        
        $lock = $this->runUpdate("
            UPDATE jobs
            SET    locked_at = NOW(), locked_by = ?
            WHERE  id = ? AND (locked_at IS NULL OR locked_by = ?) AND failed_at IS NULL
        ", array($this->worker_name, $this->job_id, $this->worker_name));
        
        if (!$lock) {
            $this->log("* [JOB] failed to acquire lock for job::{$this->job_id}");
            return false;
        }
        
        return true;
    }
    
    public function releaseLock() {
        $this->runUpdate("
            UPDATE jobs
            SET locked_at = NULL, locked_by = NULL
            WHERE id = ?",
            array($this->job_id)
        );
    }
    
    public function finish() {
        $this->runUpdate(
            "DELETE FROM jobs WHERE id = ?", 
            array($this->job_id)
        );
        $this->log("* [JOB] completed job::{$this->job_id}");
    }
    
    public function finishWithError($error) {
        $this->runUpdate("
            UPDATE jobs
            SET failed_at = NOW(), error = ?
            WHERE id = ?",
            array($error, $this->job_id)
        );
        $this->log("* [JOB] failure in job::{$this->job_id}");
        $this->releaseLock();
    }
    
    public function retryLater() {
        $this->runUpdate("
            UPDATE jobs
            SET run_at = DATE_ADD(NOW(), INTERVAL 2 HOUR)
            WHERE id = ?",
            array($this->job_id)
        );
        $this->releaseLock();
    }
    
    public function getHandler() {
        $rs = $this->runQuery(
            "SELECT handler FROM jobs WHERE id = ?", 
            array($this->job_id)
        );
        while ($rs->next()) return unserialize($rs->getString("handler"));
        return false;
    }
    
    public static function enqueue($handler, $queue = "default", $run_at = null) {
        $affected = self::runUpdate(
            "INSERT INTO jobs (handler, queue, run_at, created_at) VALUES(?, ?, ?, NOW())",
            array(serialize($handler), (string) $queue, $run_at)
        );
        
        if ($affected < 1) {
            self::log("* [JOB] failed to enqueue new job");
            return false;
        }
        
        return true;
    }
    
    public static function bulkEnqueue($handlers, $queue = "default", $run_at = null) {
        $sql = "INSERT INTO jobs (handler, queue, run_at, created_at) VALUES";
        $sql .= implode(",", array_fill(0, count($handlers), "(?, ?, ?, NOW())"));
        
        $parameters = array();
        foreach ($handlers as $handler) {
            $parameters []= serialize($handler);
            $parameters []= (string) $queue;
            $parameters []= $run_at;
        }
        $affected = self::runUpdate($sql, $parameters);
        
        if ($affected < 1) {
            self::log("* [JOB] failed to enqueue new jobs");
            return false;
        }
        
        if ($affected != count($handlers))
            self::log("* [JOB] failed to enqueue some new jobs");
        
        return true;
    }
    
    public static function status($queue = "default") {
        $rs = self::runQuery("
            SELECT COUNT(*) as total, COUNT(failed_at) as failed, COUNT(locked_at) as locked
            FROM `jobs`
            WHERE queue = ?
        ", array($queue));
        
        $rs->next();
        $failed = $rs->getInt("failed");
        $locked = $rs->getInt("locked");
        $total  = $rs->getInt("total");
        $outstanding = $total - $locked - $failed;
        
        return array(
            "outstanding" => $outstanding,
            "locked" => $locked,
            "failed" => $failed,
            "total"  => $total
        );
    }
    
}