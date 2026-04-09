<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DigipayTestCase.php';
require_once __DIR__ . '/../support/class-remote-command-handler.php';

class RemoteCommandHandlerTest extends DigipayTestCase {

    public function test_class_exists_with_poll_method() {
        $this->assertTrue( class_exists( 'WCPG_Remote_Command_Handler' ) );
        $this->assertTrue( method_exists( 'WCPG_Remote_Command_Handler', 'poll' ) );
    }
}
