<?php

use Prado\Exceptions\TConfigurationException;
use Prado\IO\Socket\WebSocket\Cluster\TFileBackplane;
use Prado\IO\Socket\WebSocket\Cluster\TWebSocketCluster;
use Prado\IO\Socket\WebSocket\TWebSocketConnection;
use Prado\IO\Stream\TBufferStream;

class TFileBackplaneTest extends PHPUnit\Framework\TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wscluster_' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		$this->removeTree($this->dir);
	}

	private function removeTree(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
			is_dir($path) ? $this->removeTree($path) : @unlink($path);
		}
		@rmdir($dir);
	}

	private function makeNode(string $nodeId): TWebSocketCluster
	{
		$backplane = new TFileBackplane();
		$backplane->setDirectory($this->dir);
		$cluster = new TWebSocketCluster($nodeId, $backplane);
		$cluster->open();
		return $cluster;
	}

	/** @return array{0: TWebSocketConnection, 1: TBufferStream} A server-side connection and its sink. */
	private function makeConnection(): array
	{
		$stream = new TBufferStream();
		return [new TWebSocketConnection($stream, false), $stream];
	}

	public function testOpenRequiresADirectory()
	{
		$cluster = new TWebSocketCluster('lonely', new TFileBackplane());
		$this->expectException(TConfigurationException::class);
		$cluster->open();
	}

	public function testPublishCrossesNodesThroughTheFiles()
	{
		$node1 = $this->makeNode('node1');
		$node2 = $this->makeNode('node2');
		[$conn, $sink] = $this->makeConnection();
		$id = $node2->register($conn);
		$node2->subscribe($id, 'news');

		$node1->publish('news', 'hello');
		self::assertSame(0, $sink->getSize(), 'Nothing is delivered until the other node ticks.');

		$node2->tick();
		self::assertGreaterThan(0, $sink->getSize(), 'A publish on node1 reaches a subscriber on node2.');
	}

	public function testBroadcastCrossesNodes()
	{
		$node1 = $this->makeNode('node1');
		$node2 = $this->makeNode('node2');
		[$conn, $sink] = $this->makeConnection();
		$node2->register($conn);

		$node1->broadcast('everyone');
		$node2->tick();
		self::assertGreaterThan(0, $sink->getSize(), 'A broadcast on node1 reaches every client on node2.');
	}

	public function testDirectMessageRoutesToTheHoldingNode()
	{
		$node1 = $this->makeNode('node1');
		$node2 = $this->makeNode('node2');
		[$conn, $sink] = $this->makeConnection();
		$remoteId = $node2->register($conn);

		$node1->tick();   // learn node2's client through the presence delta
		self::assertArrayHasKey($remoteId, $node1->presence(), 'Presence converges across nodes.');
		self::assertTrue($node1->sendToClient($remoteId, 'hi'), 'A remote client is known through the mirror.');

		$node2->tick();
		self::assertGreaterThan(0, $sink->getSize(), 'A direct send reaches the client on its node.');
	}

	public function testPresenceSeedsOnLateJoin()
	{
		$node1 = $this->makeNode('node1');
		[$conn] = $this->makeConnection();
		$id = $node1->register($conn, ['user' => 'alice']);

		$node2 = $this->makeNode('node2');   // joins after node1's client is present
		self::assertArrayHasKey($id, $node2->presence(), 'A late-joining node seeds presence from the registry.');
		self::assertSame('node1', $node2->presence()[$id]['node']);
		self::assertSame('alice', $node2->presence()[$id]['user']);
	}

	public function testPresenceDropPropagates()
	{
		$node1 = $this->makeNode('node1');
		$node2 = $this->makeNode('node2');
		[$conn] = $this->makeConnection();
		$id = $node1->register($conn);

		$node2->tick();
		self::assertArrayHasKey($id, $node2->presence());

		$node1->unregister($conn);
		$node2->tick();
		self::assertArrayNotHasKey($id, $node2->presence(), 'A departure propagates to the other node.');
	}
}
