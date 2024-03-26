<?php

namespace Snowflake53;

use Exception;

/* 10th of seconds since 1st march 2024 at 9:00:00 */
CONST EPOCH = 17092800000;

/* snowflake ID generator, 53 bits variant, use s/10, 255 machine, 1023 sequences  
 * up for more than 100 years (from 2024 to 2124, then javascript might support
 * full 64 bits integers)
 */
trait ID {
    private static function initSHM () {
        $shmId = ftok(__FILE__, 'S');
        if ($shmId === -1) {
            throw new Exception('Error creating shared memory segment');
        }

        $shm = shm_attach($shmId, 1024, 0666);
        if ($shm === false) {
            throw new Exception('Error attaching shared memory segment');
        }

        $sem = sem_get($shmId, 1, 0666);
        if ($sem === false) {
            throw new Exception('Error creating semaphore');
        }

        $maxTries = 1000;
        do {
            $maxTries--;
            if (sem_acquire($sem)) {
                break;
            }
            if ($maxTries <= 0) {
                throw new Exception('Error acquiring semaphore');
            }
            usleep(1000);
        } while (1);

        return [$shm, $sem];
    }

    private static function getSequenceId ($shm) {
        $sequenceId = 0;
        if (shm_has_var($shm, 0)) {
            $sequenceId = shm_get_var($shm, 0);
        }
        if ($sequenceId > 0x3FF) {
            $sequenceId = 0;
        }
        return $sequenceId;
    }

    private static function updateSequenceId ($shm, $sequenceId) {
        if (!shm_put_var($shm, 0, $sequenceId + 1)) {
            throw new Exception('Error updating sequence ID');
        }
    }

    private static function releaseSHM ($shm, $sem) {
        sem_release($sem);
        shm_detach($shm);
    }

    private static function getLastTime ($shm) {
        $lastTime = 0;
        if (shm_has_var($shm, 1)) {
            $lastTime = shm_get_var($shm, 1);
        }
        return $lastTime;
    }

    private static function updateLastTime ($shm, $time) {
        if (!shm_put_var($shm, 1, $time)) {
            throw new Exception('Error updating last time');
        }
    }

    private static function getMachineId () {
        $machineId = getenv('SNOWFLAK53_MACHINE_ID');
        if ($machineId === false) {
            $machineId = 0;
        } else {
            $machineId = intval($machineId) & 0xF;
        }
        return $machineId;
    }

    private static function mixValues ($time, $machineId, $sequenceId) {
        return 0 
            | ($time << 18) 
            | (($machineId & 0xF) << 10)  
            | ($sequenceId & 0x3FF);
    }

    public static function generateId():int {
        try {
            $time = (intval(microtime(true) * 10) - EPOCH) & 0x7FFFFFFFF;
            
            list($shm, $sem) = self::initSHM();
            $lastTime = self::getLastTime($shm);
            $sequenceId = 0;
            if ($time === $lastTime) {
                $sequenceId = self::getSequenceId($shm);
            }
            self::updateLastTime($shm, $time);
            if ($time === $lastTime) {
                self::updateSequenceId($shm, $sequenceId);
            } else {
                self::updateSequenceId($shm, 0);
            }
            self::releaseSHM($shm, $sem);
            $machineId = self::getMachineId();

            return self::mixValues($time, $machineId, $sequenceId);
        } catch (Exception $e) {
            throw new Exception('Error generating ID', 0, $e);
        }
    }
}