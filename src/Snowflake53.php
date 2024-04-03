<?php

namespace Snowflake53;

use Exception;

  /* 10th of seconds since 1st march 2024 at 9:00:00 */
  CONST EPOCH53 =   17092800000;
  const EPOCH64 = 1709280000000;
  const PRJ53 = '5';
  const PRJ64 = '6';
  const MACHINE53 = 0xF;
  const MACHINE64 = 0x3FF;
  const SEQ53 = 0x3FF;
  const SEQ64 = 0xFFF;

/* snowflake ID generator, 53 bits variant, use s/10, 255 machine, 1023 sequences  
 * up for more than 100 years (from 2024 to 2124, then javascript might support
 * full 64 bits integers)
 * Can also generate 64 bits IDs if needed.
 * 
 * Counter is shared between all processes on the same machine via shared memory
 * so it doesn't need to have Redis, Memcached or any other shared storage.
 */
trait ID {
    private static function initSHM ($prj = PRJ53) {
        $shmId = ftok(__FILE__, $prj);
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

    private static function getSequenceId ($shm, $seq = SEQ53) {
        $sequenceId = 0;
        if (shm_has_var($shm, 0)) {
            $sequenceId = shm_get_var($shm, 0);
        }
        if ($sequenceId > $seq) {
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

    private static function getMachineId ($mask = MACHINE53) {
        $machineId = 0;
        switch ($mask) {
            default:
            case MACHINE53:
                $machineId = getenv('SNOWFLAK53_MACHINE_ID')
                    || getenv('SNOWFLAKE64_MACHINE_ID')
                    || getenv('SNOWFLAKE_MACHINE_ID');
                break;
            case MACHINE64:
                $machineId = getenv('SNOWFLAK64_MACHINE_ID')
                    || getenv('SNOWFLAKE53_MACHINE_ID')
                    || getenv('SNOWFLAKE_MACHINE_ID');
                break;
        }
        if ($machineId === false) {
            return 0;
        }
        return intval($machineId) & $mask;
    }

    private static function mixValues53 ($time, $machineId, $sequenceId) {
        return 0 
            | ($time << 18) 
            | (($machineId & MACHINE53) << 10)  
            | ($sequenceId & SEQ53);
    }

    private static function mixValues64 ($time, $machineId, $sequenceId) {
        return 0 
            | ($time << 23) 
            | (($machineId & MACHINE64) << 13)  
            | ($sequenceId & SEQ64);
    }

    public static function generateId():int {
        try {
            $time = (intval(microtime(true) * 10) - EPOCH53) & 0x3FFFFFFFFFF;
            
            list($shm, $sem) = self::initSHM(PRJ53);
            $lastTime = self::getLastTime($shm);
            $sequenceId = 0;
            if ($time === $lastTime) {
                $sequenceId = self::getSequenceId($shm, SEQ53);
            }
            self::updateLastTime($shm, $time);
            if ($time === $lastTime) {
                self::updateSequenceId($shm, $sequenceId);
            } else {
                self::updateSequenceId($shm, 0);
            }
            self::releaseSHM($shm, $sem);
            $machineId = self::getMachineId(MACHINE53);

            return self::mixValues53($time, $machineId, $sequenceId);
        } catch (Exception $e) {
            throw new Exception('Error generating ID', 0, $e);
        }
    }

    public static function get53 ():int {
        return self::generateId();
    }

    public static function generateId64():int {
        try {
            $time = (intval(microtime(true) * 1000) - EPOCH64) & 0x7FFFFFFFF;
                        
            list($shm, $sem) = self::initSHM(PRJ64);
            $lastTime = self::getLastTime($shm);
            $sequenceId = 0;
            if ($time === $lastTime) {
                $sequenceId = self::getSequenceId($shm, SEQ64);
            }
            self::updateLastTime($shm, $time);
            if ($time === $lastTime) {
                self::updateSequenceId($shm, $sequenceId);
            } else {
                self::updateSequenceId($shm, 0);
            }
            self::releaseSHM($shm, $sem);
            $machineId = self::getMachineId(
                MACHINE64);

            return self::mixValues64($time, $machineId, $sequenceId);
        } catch (Exception $e) {
            throw new Exception('Error generating ID', 0, $e);
        }
    }   

    public static function get64 ():int {
        return self::generateId64();
    }
}