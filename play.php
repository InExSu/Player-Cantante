<?php
declare(strict_types=1);

//http://cz81095.tw1.ru/Player-Cantante/play.php

$duration = 300;

for($i = 0; $i<$duration;$i++){
    echo '$duration ' . $i;
    sleep(1);
}