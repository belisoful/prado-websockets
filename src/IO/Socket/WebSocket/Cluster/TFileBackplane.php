<?php

/**
 * TFileBackplane class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-websockets
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Socket\WebSocket\Cluster;

use Prado\Exceptions\TConfigurationException;
use Prado\TComponent;

/**
 * TFileBackplane class.
 *
 * A backplane that carries cluster traffic through a shared directory, so several nodes on one host
 * (or any hosts sharing a filesystem) form a cluster with no service to run.  It is the natural
 * driver for development and tests: two {@see TWebSocketCluster} coordinators pointed at the same
 * {@see getDirectory() Directory} relay to each other through the files.
 *
 * Layout under the directory:
 *  - `messages.log` — an append-only log of encoded {@see TWebSocketEnvelope}s.  Every node appends
 *    its outbound traffic (under an exclusive lock) and, on each {@see tick()}, reads the entries
 *    written since its last read.  Every node sees all traffic; the coordinator enforces routing,
 *    so {@see subscribe()}/{@see unsubscribe()} are inert.
 *  - `presence/` — one file per present client, named for the client id and holding its metadata.
 *    This is the shared registry a late-joining node reads in {@see open()} to converge, while live
 *    changes also flow as presence envelopes through the log.
 *
 * The log grows without bound, so this driver suits development, tests, and small clusters; a
 * high-throughput deployment uses a service-backed driver (Redis).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.4.0
 */
class TFileBackplane extends TComponent implements IWebSocketBackplane
{
	/** The log file name within the directory. */
	public const LOG_FILE = 'messages.log';

	/** The presence subdirectory within the directory. */
	public const PRESENCE_DIR = 'presence';

	/** @var ?IWebSocketCluster The owning coordinator. */
	private ?IWebSocketCluster $_cluster = null;

	/** @var ?string The shared cluster directory. */
	private ?string $_directory = null;

	/** @var int The byte offset read up to in the log. */
	private int $_offset = 0;

	/**
	 * Binds the owning coordinator.
	 * @param IWebSocketCluster $cluster The coordinator received envelopes are delivered to.
	 */
	public function setCluster(IWebSocketCluster $cluster): void
	{
		$this->_cluster = $cluster;
	}

	/**
	 * Returns the shared cluster directory.
	 * @return ?string The directory, or null when unset.
	 */
	public function getDirectory(): ?string
	{
		return $this->_directory;
	}

	/**
	 * Sets the shared cluster directory.
	 * @param string $value The directory path.
	 * @return static The current backplane.
	 */
	public function setDirectory($value): static
	{
		$this->_directory = $value === '' ? null : $value;
		return $this;
	}

	/**
	 * Joins the cluster: creates the directory layout, starts reading the log from its current end
	 * (so prior traffic is not replayed), and seeds the presence mirror from the registry.
	 * @throws TConfigurationException When the directory is unset or cannot be created.
	 */
	public function open(): void
	{
		if ($this->_directory === null) {
			throw new TConfigurationException('websocket_backplane_directory_required');
		}
		$presence = $this->_directory . DIRECTORY_SEPARATOR . self::PRESENCE_DIR;
		if (!is_dir($presence) && !@mkdir($presence, 0o777, true) && !is_dir($presence)) {
			throw new TConfigurationException('websocket_backplane_directory_unwritable', $this->_directory);
		}
		clearstatcache();
		$log = $this->logPath();
		$this->_offset = is_file($log) ? (int) filesize($log) : 0;
		$this->seedPresence();
	}

	/**
	 * Leaves the cluster.  The coordinator has already dropped its clients' presence, so there is
	 * nothing further to release.
	 */
	public function close(): void
	{
		$this->_offset = 0;
	}

	/**
	 * Reads and delivers the log entries appended since the last tick.
	 */
	public function tick(): void
	{
		if ($this->_directory === null || $this->_cluster === null) {
			return;
		}
		$fp = @fopen($this->logPath(), 'rb');
		if ($fp === false) {
			return;
		}
		$envelopes = [];
		flock($fp, LOCK_SH);
		fseek($fp, $this->_offset);
		while (($line = fgets($fp)) !== false) {
			$line = rtrim($line, "\n");
			if ($line !== '' && ($envelope = TWebSocketEnvelope::decode($line)) !== null) {
				$envelopes[] = $envelope;
			}
		}
		$this->_offset = ftell($fp);
		flock($fp, LOCK_UN);
		fclose($fp);

		foreach ($envelopes as $envelope) {
			$this->_cluster->receiveEnvelope($envelope);
		}
	}

	/**
	 * Returns no resources; a file is polled in {@see tick()}, not selected on.
	 * @return \Prado\IO\IResource[] An empty array.
	 */
	public function getSources(): array
	{
		return [];
	}

	/**
	 * Appends an envelope to the shared log.
	 * @param TWebSocketEnvelope $envelope The envelope to publish.
	 */
	public function publish(TWebSocketEnvelope $envelope): void
	{
		$this->append($envelope);
	}

	/**
	 * Does nothing; every node reads all log traffic, so channel interest needs no declaration.
	 * @param string $channel The channel (ignored).
	 */
	public function subscribe(string $channel): void
	{
	}

	/**
	 * Does nothing; every node reads all log traffic, so channel interest needs no declaration.
	 * @param string $channel The channel (ignored).
	 */
	public function unsubscribe(string $channel): void
	{
	}

	/**
	 * Records a client in the presence registry and announces it through the log.
	 * @param string $clientId The cluster client id.
	 * @param array<string, mixed> $meta The presence metadata (already carries the node id).
	 */
	public function putPresence(string $clientId, array $meta): void
	{
		if ($this->_directory === null) {
			return;
		}
		@file_put_contents($this->presencePath($clientId), (string) json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$node = $this->_cluster !== null ? $this->_cluster->getNodeId() : (string) ($meta['node'] ?? '');
		$this->append(new TWebSocketEnvelope(TWebSocketEnvelope::PRESENCE_SET, $node, '', null, $clientId, $meta));
	}

	/**
	 * Removes a client from the presence registry and announces its departure through the log.
	 * @param string $clientId The cluster client id.
	 */
	public function dropPresence(string $clientId): void
	{
		if ($this->_directory === null) {
			return;
		}
		@unlink($this->presencePath($clientId));
		$node = $this->_cluster !== null ? $this->_cluster->getNodeId() : '';
		$this->append(new TWebSocketEnvelope(TWebSocketEnvelope::PRESENCE_DROP, $node, '', null, $clientId));
	}

	/**
	 * Appends an encoded envelope to the log under an exclusive lock.
	 * @param TWebSocketEnvelope $envelope The envelope to append.
	 */
	private function append(TWebSocketEnvelope $envelope): void
	{
		if ($this->_directory === null) {
			return;
		}
		$fp = @fopen($this->logPath(), 'ab');
		if ($fp === false) {
			return;
		}
		flock($fp, LOCK_EX);
		fwrite($fp, $envelope->encode() . "\n");
		fflush($fp);
		flock($fp, LOCK_UN);
		fclose($fp);
	}

	/**
	 * Seeds the presence mirror from the registry, delivering a presence envelope per known client.
	 */
	private function seedPresence(): void
	{
		if ($this->_cluster === null) {
			return;
		}
		foreach (glob($this->_directory . DIRECTORY_SEPARATOR . self::PRESENCE_DIR . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
			$meta = json_decode((string) @file_get_contents($file), true);
			if (!is_array($meta)) {
				continue;
			}
			$clientId = rawurldecode(basename($file));
			$this->_cluster->receiveEnvelope(new TWebSocketEnvelope(TWebSocketEnvelope::PRESENCE_SET, (string) ($meta['node'] ?? ''), '', null, $clientId, $meta));
		}
	}

	/**
	 * Returns the log file path.
	 * @return string The log path.
	 */
	private function logPath(): string
	{
		return $this->_directory . DIRECTORY_SEPARATOR . self::LOG_FILE;
	}

	/**
	 * Returns the presence file path for a client id.
	 * @param string $clientId The cluster client id.
	 * @return string The presence file path.
	 */
	private function presencePath(string $clientId): string
	{
		return $this->_directory . DIRECTORY_SEPARATOR . self::PRESENCE_DIR . DIRECTORY_SEPARATOR . rawurlencode($clientId);
	}
}
