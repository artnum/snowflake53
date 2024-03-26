# Snowflake53

A trait to generate 53 bits snowflake ID. ID fit into js Number.MAX_SAFE_INTEGER.
Sequence counting is done with shared memory and machine ID comes from
environment variable.

## Usage

```php
require_once 'vendor/autoload.php';

use Snowflake53\ID;

class IDGen {
    use ID;
}

$gen = new IDGen();
echo $gen->generateId() . PHP_EOL;

```