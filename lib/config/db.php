<?php
return array(
    'shop_filler_category' => array(
        'id' => array('int', 10, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'url' => array('text'),
        'shop_category_id' => array('int', 10, 'unsigned' => 1),
        ':keys' => array(
            'PRIMARY' => 'id',
        ),
    ),
    'shop_filler_product' => array(
        'id' => array('int', 10, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'sku' => array('varchar', 250),
        'filler_category_id' => array('int', 10, 'unsigned' => 1),
        'shop_product_id' => array('int', 10, 'unsigned' => 1),
        'product_string' => array('text'),
        ':keys' => array(
            'PRIMARY' => 'id',
            'sku' => array('sku', 'unique' => 1),
        ),
    ),
    'shop_filler_docs' => array(
        'id' => array('int', 10, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'url' => array('text'),
        ':keys' => array(
            'PRIMARY' => 'id',
        ),
    ),
);
