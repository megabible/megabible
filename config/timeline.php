<?php

// Composition-era palette for layered timeline bars. Each layer is coloured by
// the era its midpoint falls in. Colours are --tl-* token names from app.blade.php.
// Eras must be contiguous and ordered earliest → latest.
return [
    'eras' => [
        ['label' => 'Monarchic (pre-586 BC)',   'start' => -1050, 'end' => -586, 'color' => 'gold'],
        ['label' => 'Exilic (586-538 BC)',      'start' => -586,  'end' => -538, 'color' => 'terracotta'],
        ['label' => 'Persian (538-332 BC)',     'start' => -538,  'end' => -332, 'color' => 'teal'],
        ['label' => 'Hellenistic (332-63 BC)', 'start' => -332,  'end' => -63,  'color' => 'olive'],
        ['label' => 'Roman (63 BC on)',       'start' => -63,   'end' => 400,  'color' => 'moss'],
    ],
];