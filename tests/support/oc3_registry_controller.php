<?php
/** Isolated registry bridge for controller tests; not a live OpenCart bootstrap or core fixture. */
if (!class_exists('Controller', false)) {
    abstract class Controller
    {
        protected $registry;
        public function __construct($registry) { $this->registry = $registry; }
        public function __get($key) { return $this->registry->get($key); }
        public function __set($key, $value) { $this->registry->set($key, $value); }
    }
}
