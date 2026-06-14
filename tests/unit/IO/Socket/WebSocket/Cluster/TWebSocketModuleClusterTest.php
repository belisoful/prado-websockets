<?php

use Prado\Exceptions\TConfigurationException;
use Prado\IO\Socket\WebSocket\Cluster\TFileBackplane;
use Prado\IO\Socket\WebSocket\Cluster\TNullBackplane;
use Prado\IO\Socket\WebSocket\TWebSocketConnection;
use Prado\IO\Socket\WebSocket\TWebSocketModule;
use Prado\IO\Socket\WebSocket\TWebSocketServer;
use Prado\IO\Stream\TBufferStream;

/** Exposes the protected backplane factory for direct testing. */
class TestableWebSocketModule extends TWebSocketModule
{
	public function createBackplanePublic(array $properties): void
	{
		$this->createBackplane($properties);
	}
}

class TWebSocketModuleClusterTest extends PHPUnit\Framework\TestCase
{
	private array $tempDirs = [];

	protected function tearDown(): void
	{
		foreach ($this->tempDirs as $dir) {
			foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
				is_dir($f) ? @rmdir($f) : @unlink($f);
			}
			foreach (glob($dir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
				@unlink($f);
			}
			@rmdir($dir . DIRECTORY_SEPARATOR . TFileBackplane::PRESENCE_DIR);
			@rmdir($dir);
		}
	}

	private function tempDir(): string
	{
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wsmod_' . uniqid('', true);
		$this->tempDirs[] = $dir;
		return $dir;
	}

	public function testClusterUsesConfiguredNodeIdAndBackplane()
	{
		$module = new TWebSocketModule();
		$module->setNodeId('edge-1');
		$backplane = new TNullBackplane();
		$module->setBackplane($backplane);

		$cluster = $module->getCluster();
		self::assertSame('edge-1', $cluster->getNodeId());
		self::assertSame($backplane, $cluster->getBackplane());
		self::assertSame($cluster, $module->getCluster(), 'The cluster is created once.');
	}

	public function testSetBackplaneAfterClusterCreationUpdatesIt()
	{
		$module = new TWebSocketModule();
		$cluster = $module->getCluster();   // creates with the default null backplane
		$backplane = new TNullBackplane();
		$module->setBackplane($backplane);
		self::assertSame($backplane, $cluster->getBackplane(), 'A later backplane is applied to the live cluster.');
	}

	public function testPrepareServerWiresTheClusterIntoTheServer()
	{
		$module = new TWebSocketModule();
		$server = new TWebSocketServer();
		$module->prepareServer($server);
		self::assertSame($module->getCluster(), $server->getCluster());
	}

	public function testCreateBackplaneInstantiatesAndConfigures()
	{
		$dir = $this->tempDir();
		$module = new TestableWebSocketModule();
		$module->createBackplanePublic(['class' => TFileBackplane::class, 'Directory' => $dir]);

		$backplane = $module->getBackplane();
		self::assertInstanceOf(TFileBackplane::class, $backplane);
		self::assertSame($dir, $backplane->getDirectory(), 'Configured attributes are applied to the backplane.');
	}

	public function testCreateBackplaneRejectsAnInvalidClass()
	{
		$module = new TestableWebSocketModule();
		$this->expectException(TConfigurationException::class);
		$module->createBackplanePublic(['class' => \stdClass::class]);
	}

	public function testConvenienceApiPublishesThroughTheCluster()
	{
		$dir = $this->tempDir();
		$module = new TWebSocketModule();
		$module->setNodeId('n1');
		$backplane = new TFileBackplane();
		$backplane->setDirectory($dir);
		$module->setBackplane($backplane);

		$cluster = $module->getCluster();
		$cluster->open();
		$stream = new TBufferStream();
		$connection = new TWebSocketConnection($stream, false);
		$id = $cluster->register($connection);
		$cluster->subscribe($id, 'news');

		$module->publish('news', 'hello');
		self::assertGreaterThan(0, $stream->getSize(), 'Module publish reaches a local subscriber through the cluster.');

		$cluster->close();
	}
}
