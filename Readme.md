# Snowflake53

A trait to generate 53 bits snowflake id to fit into js Number.MAX_SAFE_INTEGER.
Sequence counting is done with shared memory and machine ID comes from
environment variable or parameters
It also possible to generate 64 bits snowflake id.

It dosen't require any external infrastructure (like Memcached or Redis or ...)
to keep sequence counting as it is done by shared memory and use semaphore for
locking. So other processes can tap into the sequence counting too :)

## Install

Via composer :

```
$ composer require artnum/snowflake53
```

## Usage

```php
require_once 'vendor/autoload.php';

use Snowflake53\ID;

class IDGen {
    use ID;
}

$gen = new IDGen();
echo $gen->get53() . PHP_EOL;
echo $gen->get64() . PHP_EOL;

```

All functions are declared as static, so it is possible to use without a class
instance.

```php
require_once 'vendor/autoload.php';

use Snowflake53\ID;

class IDGen {
    use ID;
}

echo IDGen::get53() . PHP_EOL;
echo IDGen::get64() . PHP_EOL;
```

You can destroy, if you choose to, shared memory semgent and semaphore by calling
`destroySHM`:

```php
require_once 'vendor/autoload.php';

use Snowflake53\ID;

class IDGen {
    use ID;
}

IDGen::destroySHM(); 
```

## Shared memory path

Shared memory use a file path as identity. By default it is `__FILE__`.  But if
you use this in several different places on your server but still want all ID
to be in sequence, you can set the public static variable $SHMPath to the path
you want. The file __must__ exist.

```php
require_once 'src/Snowflake53.php';

use Snowflake53\ID;

class IDGen {
    use ID;
}

IDGen::$SHMPath = '/tmp/snowflake53';
echo IDGen::get53() . PHP_EOL;
echo IDGen::get64() . PHP_EOL;


$gen = new IDGen();
$gen::$SHMPath = '/tmp/snowflake53';
echo $gen->get53() . PHP_EOL;
echo $gen->get64() . PHP_EOL;
```

## Machine ID

Machine ID can be passed as argument of `get53` or `get64`. If not, it will try to
get the ID from environment variable `SNOWFLAK53_MACHINE_ID`, 
`SNOWFLAKE64_MACHINE_ID` or `SNOWFLAKE_MACHINE_ID`. 
When getting a 53 bits ID, `SNOWFLAK53_MACHINE_ID` will be look at and then 
`SNOWFLAKE_MACHINE_ID`, when getting a 64 bits ID it's the opposite (first
`SNOWFLAKE64_MACHINE_ID` and then `SNOWFLAKE_MACHINE_ID`).

The idea behind that is you set a unique machine  for 64 and 53 bits and so, you
use `SNOWFLAKE_MACHINE_ID` or you have a different machine ID for 53 bits or 64
bits and so you set `SNOWFLAKE53_MACHINE_ID` and `SNOWFLAKE64_MACHINE_ID`.

## License

Under [MIT license](https://opensource.org/license/mit).

## Developer

 * Etienne Bagnoud <etienne@artnum.ch>