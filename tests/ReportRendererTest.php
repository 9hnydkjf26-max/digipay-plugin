<?php
/**
 * Tests for WCPG_Report_Renderer.
 *
 * @package Digipay
 */

require_once __DIR__ . '/../support/class-context-bundler.php';
require_once __DIR__ . '/../support/class-report-renderer.php';

/**
 * Report renderer tests.
 */
class ReportRendererTest extends DigipayTestCase {

	/**
	 * Rendering a bundle produces markdown containing each section heading.
	 */
	public function test_renders_all_sections() {
		$bundle = ( new WCPG_Context_Bundler() )->build();
		$md     = ( new WCPG_Report_Renderer() )->render( $bundle );

		$this->assertStringContainsString( '# Digipay Diagnostic Report', $md );
		$this->assertStringContainsString( '## Site', $md );
		$this->assertStringContainsString( '## Gateways', $md );
		$this->assertStringContainsString( '## Logs', $md );
		$this->assertStringContainsString( '## Recent Failed / Stuck Orders', $md );
		$this->assertStringContainsString( '## Option Snapshots', $md );
		$this->assertStringContainsString( 'SHA-256', $md );
	}

	/**
	 * Gateway sections are rendered as fenced JSON blocks.
	 */
	public function test_gateway_sections_are_json_fenced() {
		$bundle = array(
			'bundle_meta' => array( 'bundle_id' => 'x', 'generated_at_utc' => 'z', 'generator_version' => '1', 'content_sha256' => 'abc' ),
			'gateways'    => array( 'paygobillingcc' => array( 'enabled' => 'yes' ) ),
		);
		$md = ( new WCPG_Report_Renderer() )->render( $bundle );
		$this->assertStringContainsString( '### paygobillingcc', $md );
		$this->assertStringContainsString( '"enabled": "yes"', $md );
	}
}
