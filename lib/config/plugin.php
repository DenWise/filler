<?php

return array(
    'name'        => 'Наполнение сайта товарами',
    'description' => 'Производит автоматическое наполнение сайта товарами и обновление информации о товарах',
    'version'     => '1.0.0',
    'handlers' => array(
        'product_delete' => 'productDelete',
        'category_delete' => 'categoryDelete'
    ),
);
