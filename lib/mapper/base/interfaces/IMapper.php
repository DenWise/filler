<?php

    interface IMapper
    {
        public function getMaps();
        public function getMapsCount();
        public function createMaps($path);
        public function getProductData($url);
        public function getConfigField($field);
    }