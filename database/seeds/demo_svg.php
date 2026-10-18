<?php

declare(strict_types=1);

/**
 * Simple vector product illustrations for demo data (replace with real photos in production).
 * Returns an SVG document string. $kind selects the shape, $color is the object colour.
 */
function demo_product_svg(string $kind, string $color, string $bg): string
{
    $c = htmlspecialchars($color, ENT_QUOTES);
    $dark = '#263238';
    $shapes = [
        'phone' => "<rect x='140' y='70' width='120' height='240' rx='20' fill='$c'/><rect x='150' y='88' width='100' height='196' rx='6' fill='#cfe8f6'/><circle cx='200' cy='296' r='6' fill='$dark'/><rect x='184' y='78' width='32' height='5' rx='2' fill='$dark'/><path d='M150 88h100v60L150 220z' fill='#fff' opacity='.35'/>",
        'basicphone' => "<rect x='150' y='80' width='100' height='230' rx='24' fill='$c'/><rect x='162' y='98' width='76' height='70' rx='4' fill='#cfe8f6'/>" . implode('', array_map(fn ($i) => "<rect x='" . (166 + ($i % 3) * 24) . "' y='" . (184 + intdiv($i, 3) * 26) . "' width='18' height='16' rx='4' fill='#eceff1'/>", range(0, 11))),
        'tablet' => "<rect x='95' y='90' width='210' height='220' rx='18' fill='$c'/><rect x='108' y='104' width='184' height='192' rx='6' fill='#cfe8f6'/><path d='M108 104h184v50L108 250z' fill='#fff' opacity='.35'/>",
        'laptop' => "<rect x='100' y='110' width='200' height='130' rx='10' fill='$c'/><rect x='112' y='122' width='176' height='106' rx='3' fill='#cfe8f6'/><path d='M70 250h260l-18 26H88z' fill='$c'/><rect x='175' y='252' width='50' height='6' rx='3' fill='$dark' opacity='.4'/>",
        'monitor' => "<rect x='85' y='95' width='230' height='150' rx='8' fill='$c'/><rect x='96' y='106' width='208' height='128' fill='#cfe8f6'/><rect x='190' y='245' width='20' height='40' fill='$c'/><rect x='150' y='284' width='100' height='12' rx='6' fill='$c'/>",
        'tv' => "<rect x='60' y='100' width='280' height='170' rx='8' fill='$dark'/><rect x='70' y='110' width='260' height='150' fill='$c'/><path d='M70 110h260v40L70 240z' fill='#fff' opacity='.2'/><rect x='120' y='270' width='12' height='30' fill='$dark'/><rect x='268' y='270' width='12' height='30' fill='$dark'/>",
        'headphones' => "<path d='M120 230v-40a80 80 0 0 1 160 0v40' fill='none' stroke='$c' stroke-width='18'/><rect x='100' y='215' width='50' height='80' rx='18' fill='$c'/><rect x='250' y='215' width='50' height='80' rx='18' fill='$c'/>",
        'earbuds' => "<rect x='120' y='170' width='160' height='110' rx='50' fill='#eceff1'/><rect x='120' y='170' width='160' height='40' rx='20' fill='$c'/><circle cx='170' cy='245' r='16' fill='$c'/><circle cx='230' cy='245' r='16' fill='$c'/>",
        'speaker' => "<rect x='130' y='80' width='140' height='240' rx='24' fill='$c'/><circle cx='200' cy='240' r='48' fill='$dark'/><circle cx='200' cy='240' r='20' fill='#90a4ae'/><circle cx='200' cy='135' r='24' fill='$dark'/>",
        'camera' => "<rect x='90' y='140' width='220' height='140' rx='18' fill='$c'/><rect x='150' y='118' width='70' height='30' rx='6' fill='$c'/><circle cx='200' cy='210' r='52' fill='$dark'/><circle cx='200' cy='210' r='30' fill='#4fc3f7'/>",
        'printer' => "<rect x='120' y='90' width='160' height='70' fill='#eceff1'/><rect x='80' y='150' width='240' height='110' rx='14' fill='$c'/><rect x='120' y='230' width='160' height='80' fill='#fff' stroke='#cfd8dc'/>",
        'mouse' => "<rect x='150' y='110' width='100' height='180' rx='50' fill='$c'/><line x1='200' y1='110' x2='200' y2='180' stroke='$dark' stroke-width='4'/>",
        'fridge' => "<rect x='130' y='60' width='140' height='280' rx='12' fill='$c'/><line x1='130' y1='160' x2='270' y2='160' stroke='$dark' stroke-width='4' opacity='.4'/><rect x='248' y='100' width='8' height='40' rx='4' fill='$dark' opacity='.5'/><rect x='248' y='180' width='8' height='60' rx='4' fill='$dark' opacity='.5'/>",
        'microwave' => "<rect x='70' y='120' width='260' height='160' rx='12' fill='$c'/><rect x='88' y='138' width='170' height='124' rx='6' fill='$dark' opacity='.8'/><circle cx='295' cy='170' r='10' fill='$dark'/><circle cx='295' cy='210' r='10' fill='$dark'/>",
        'cooker' => "<rect x='100' y='110' width='200' height='220' rx='8' fill='$c'/><rect x='100' y='110' width='200' height='40' fill='$dark' opacity='.6'/><rect x='125' y='180' width='150' height='110' rx='6' fill='$dark' opacity='.75'/>",
        'washer' => "<rect x='110' y='70' width='180' height='260' rx='14' fill='$c'/><circle cx='200' cy='210' r='70' fill='$dark' opacity='.8'/><circle cx='200' cy='210' r='48' fill='#4fc3f7' opacity='.8'/><rect x='130' y='90' width='60' height='16' rx='4' fill='$dark' opacity='.4'/>",
        'blender' => "<path d='M150 80h100l-15 150h-70z' fill='#b3e5fc' opacity='.85'/><rect x='140' y='230' width='120' height='90' rx='14' fill='$c'/><circle cx='200' cy='275' r='14' fill='$dark'/>",
        'kettle' => "<path d='M130 140h140l10 170H120z' fill='$c'/><path d='M270 170c40 0 40 90 0 90' fill='none' stroke='$c' stroke-width='14'/><path d='M130 160l-40-30' stroke='$c' stroke-width='16' stroke-linecap='round'/><rect x='110' y='310' width='180' height='14' rx='7' fill='$dark'/>",
        'iron' => "<path d='M90 270h230c0-70-60-110-140-110H140z' fill='$c'/><path d='M170 160c0-40 20-60 60-60h40' fill='none' stroke='$dark' stroke-width='16'/>",
        'shirt' => "<path d='M150 80l-70 40 25 55 30-15v160h130V160l30 15 25-55-70-40c-10 20-30 30-50 30s-40-10-50-30z' fill='$c'/>",
        'dress' => "<path d='M170 70h60l-5 60 65 180H110l65-180z' fill='$c'/><rect x='168' y='126' width='64' height='12' fill='$dark' opacity='.3'/>",
        'trousers' => "<path d='M140 70h120l15 260h-55l-20-180-20 180h-55z' fill='$c'/>",
        'shoe' => "<path d='M70 250c0-40 20-80 50-90l40 30c30 20 70 20 110 30 40 10 60 30 60 60H70z' fill='$c'/><rect x='70' y='270' width='260' height='18' rx='8' fill='#eceff1'/>",
        'watch' => "<rect x='170' y='70' width='60' height='260' rx='16' fill='$dark'/><circle cx='200' cy='200' r='62' fill='$c'/><circle cx='200' cy='200' r='48' fill='#fff'/><path d='M200 165v35l25 15' stroke='$dark' stroke-width='6' fill='none' stroke-linecap='round'/>",
        'bag' => "<rect x='100' y='150' width='200' height='170' rx='16' fill='$c'/><path d='M150 150v-30a50 50 0 0 1 100 0v30' fill='none' stroke='$c' stroke-width='14'/>",
        'bottle' => "<rect x='170' y='70' width='60' height='40' rx='6' fill='$dark'/><rect x='145' y='110' width='110' height='210' rx='26' fill='$c'/><rect x='160' y='170' width='80' height='80' rx='6' fill='#fff' opacity='.7'/>",
        'jar' => "<rect x='120' y='130' width='160' height='40' rx='8' fill='$dark'/><rect x='110' y='165' width='180' height='150' rx='24' fill='$c'/><rect x='140' y='210' width='120' height='60' rx='6' fill='#fff' opacity='.7'/>",
        'perfume' => "<rect x='180' y='90' width='40' height='40' fill='$dark'/><rect x='130' y='130' width='140' height='180' rx='20' fill='$c' opacity='.85'/><rect x='160' y='180' width='80' height='60' rx='4' fill='#fff' opacity='.6'/>",
        'sofa' => "<rect x='80' y='170' width='240' height='90' rx='20' fill='$c'/><rect x='110' y='130' width='180' height='70' rx='16' fill='$c' opacity='.85'/><rect x='60' y='190' width='50' height='90' rx='16' fill='$c'/><rect x='290' y='190' width='50' height='90' rx='16' fill='$c'/><rect x='90' y='280' width='14' height='30' fill='$dark'/><rect x='296' y='280' width='14' height='30' fill='$dark'/>",
        'lamp' => "<path d='M150 100h100l30 90H120z' fill='$c'/><rect x='195' y='190' width='10' height='110' fill='$dark'/><rect x='150' y='300' width='100' height='16' rx='8' fill='$dark'/>",
        'pot' => "<rect x='100' y='170' width='200' height='130' rx='18' fill='$c'/><rect x='90' y='155' width='220' height='22' rx='10' fill='$dark' opacity='.7'/><rect x='60' y='190' width='40' height='14' rx='7' fill='$dark'/><rect x='300' y='190' width='40' height='14' rx='7' fill='$dark'/>",
        'bedding' => "<rect x='70' y='160' width='260' height='120' rx='14' fill='$c'/><rect x='90' y='140' width='100' height='50' rx='18' fill='#fff'/><rect x='210' y='140' width='100' height='50' rx='18' fill='#fff'/>",
        'box' => "<rect x='120' y='80' width='160' height='240' rx='8' fill='$c'/><rect x='140' y='140' width='120' height='90' rx='6' fill='#fff' opacity='.85'/><circle cx='200' cy='270' r='20' fill='#fdb913'/>",
        'sack' => "<path d='M130 110h140l20 210H110z' fill='$c'/><rect x='125' y='100' width='150' height='22' rx='6' fill='$dark' opacity='.6'/><rect x='150' y='180' width='100' height='70' rx='6' fill='#fff' opacity='.85'/>",
        'can' => "<rect x='145' y='90' width='110' height='220' rx='16' fill='$c'/><ellipse cx='200' cy='92' rx='55' ry='10' fill='#cfd8dc'/><rect x='145' y='170' width='110' height='60' fill='#fff' opacity='.6'/>",
        'detergent' => "<rect x='130' y='110' width='140' height='210' rx='20' fill='$c'/><rect x='215' y='80' width='40' height='40' rx='8' fill='$dark'/><path d='M150 110c0-30 30-30 30 0' fill='none' stroke='$c' stroke-width='12'/><rect x='150' y='180' width='100' height='70' rx='6' fill='#fff' opacity='.8'/>",
        'diaper' => "<rect x='100' y='110' width='200' height='190' rx='18' fill='$c'/><path d='M140 160c20 60 100 60 120 0' fill='none' stroke='#fff' stroke-width='12'/><circle cx='200' cy='240' r='26' fill='#fff' opacity='.9'/>",
        'babybottle' => "<path d='M185 60h30l10 40h-50z' fill='#ffcc80'/><rect x='165' y='100' width='70' height='26' rx='6' fill='$c'/><rect x='160' y='126' width='80' height='190' rx='26' fill='#e1f5fe' stroke='$c' stroke-width='6'/>",
        'toy' => "<circle cx='200' cy='210' r='90' fill='$c'/><circle cx='170' cy='190' r='12' fill='$dark'/><circle cx='230' cy='190' r='12' fill='$dark'/><path d='M165 235c20 20 50 20 70 0' fill='none' stroke='$dark' stroke-width='8' stroke-linecap='round'/>",
        'ball' => "<circle cx='200' cy='200' r='110' fill='#fff' stroke='$dark' stroke-width='6'/><polygon points='200,150 240,180 225,225 175,225 160,180' fill='$c'/><path d='M200 150V92M240 180l52-18M225 225l30 48M175 225l-30 48M160 180l-52-18' stroke='$dark' stroke-width='6'/>",
        'dumbbell' => "<rect x='120' y='190' width='160' height='20' rx='10' fill='$dark'/><rect x='80' y='140' width='50' height='120' rx='12' fill='$c'/><rect x='270' y='140' width='50' height='120' rx='12' fill='$c'/>",
        'bike' => "<circle cx='120' cy='250' r='60' fill='none' stroke='$dark' stroke-width='10'/><circle cx='280' cy='250' r='60' fill='none' stroke='$dark' stroke-width='10'/><path d='M120 250l60-90h80l20 90M180 160l40 90h-100' fill='none' stroke='$c' stroke-width='12' stroke-linejoin='round'/>",
        'gamepad' => "<path d='M110 170h180c40 0 60 100 30 120-20 14-40-10-60-30H160c-20 20-40 44-60 30-30-20-10-120 10-120z' fill='$c'/><rect x='135' y='200' width='40' height='12' rx='4' fill='#fff'/><rect x='149' y='186' width='12' height='40' rx='4' fill='#fff'/><circle cx='250' cy='195' r='9' fill='#fff'/><circle cx='272' cy='215' r='9' fill='#fff'/>",
        'console' => "<rect x='90' y='150' width='220' height='110' rx='16' fill='$c'/><rect x='110' y='170' width='120' height='70' rx='6' fill='$dark' opacity='.8'/><circle cx='270' cy='205' r='18' fill='$dark' opacity='.6'/>",
        'disc' => "<rect x='120' y='80' width='160' height='230' rx='8' fill='$c'/><rect x='135' y='100' width='130' height='150' rx='4' fill='#fff' opacity='.85'/><circle cx='200' cy='175' r='40' fill='#fdb913'/>",
    ];
    $shape = $shapes[$kind] ?? $shapes['box'];
    return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 400' width='400' height='400'>"
        . "<rect width='400' height='400' fill='" . htmlspecialchars($bg, ENT_QUOTES) . "'/>"
        . "<ellipse cx='200' cy='345' rx='120' ry='14' fill='#000' opacity='.06'/>"
        . $shape . '</svg>';
}
