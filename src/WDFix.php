<?php
/*
 *    Copyright 2022 Jan Sohn / xxAROX
 *
 *    Licensed under the Apache License, Version 2.0 (the "License");
 *    you may not use this file except in compliance with the License.
 *    You may obtain a copy of the License at
 *
 *        http://www.apache.org/licenses/LICENSE-2.0
 *
 *    Unless required by applicable law or agreed to in writing, software
 *    distributed under the License is distributed on an "AS IS" BASIS,
 *    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *    See the License for the specific language governing permissions and
 *    limitations under the License.
 *
 */
declare(strict_types=1);
namespace xxAROX\WDFix;
use JsonMapper;
use JsonMapper_Exception;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;
use pocketmine\plugin\ResourceProvider;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\Internet;
use pocketmine\utils\SingletonTrait;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Throwable;


/**
 * Class WaterdogExtrasLoginPacketHandler
 * @package xxAROX\WDFix
 * @author Jan Sohn / xxAROX
 * @date 10. August, 2022 - 18:25
 * @ide PhpStorm
 * @project WaterdogPE-LoginExteras-Fixer
 */
class WaterdogExtrasLoginPacketHandler extends LoginPacketHandler{
	public function __construct(Server $server, NetworkSession $session, string $Waterdog_XUID){
		$playerInfoConsumer = function (XboxLivePlayerInfo $info) use ($session, $Waterdog_XUID): void{
			$class = new ReflectionClass($session);
			$property = $class->getProperty("info");
			$property->setAccessible(true);
			$property->setValue($session, new XboxLivePlayerInfo($Waterdog_XUID, $info->getUsername(), $info->getUuid(), $info->getSkin(), $info->getLocale(), $info->getExtraData()));
		};
		$authCallback = function (bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) use ($session): void{
			$class = new ReflectionClass($session);
			$method = $class->getMethod("setAuthenticationStatus");
			$method->setAccessible(true);
			$method->invoke($session, $isAuthenticated, $authRequired, $error, $clientPubKey);
		};
		parent::__construct($server, $session, $playerInfoConsumer, $authCallback);
	}
	/**
	 * Function parseClientData
	 * @param string $clientDataJwt
	 * @return ClientData
	 */
	protected function parseClientData(string $clientDataJwt): ClientData{
		try {
			[, $clientDataClaims,] = JwtUtils::parse($clientDataJwt);
		} catch (JwtException $e) {
			throw PacketHandlingException::wrap($e);
		}
		$mapper = new JsonMapper;
		$mapper->bEnforceMapType = false;
		$mapper->bExceptionOnMissingData = true;
		$mapper->bExceptionOnUndefinedProperty = true;
		try {
			$clientDataProperties = array_map(fn (ReflectionProperty $property) => $property->getName(), (new ReflectionClass(ClientData::class))->getProperties());
			foreach ($clientDataClaims as $k => $v) {
				if (!in_array($k, $clientDataProperties)) unset($clientDataClaims[$k]);
			}
			unset($properties);
			$clientData = $mapper->map($clientDataClaims, new ClientData);
		} catch (JsonMapper_Exception $e) {
			throw PacketHandlingException::wrap($e);
		}
		return $clientData;
	}
}

/**
 * Class WDFix
 * @package xxAROX\WDFix
 * @author Jan Sohn / xxAROX
 * @date 17. Januar, 2022 - 22:52
 * @ide PhpStorm
 * @project WaterdogPE-LoginExtras-Fixer
 */
class WDFix extends PluginBase implements Listener{
	private static bool $KICK_PLAYERS = false;
	private static string $KICK_MESSAGE = "";

	use SingletonTrait{
		setInstance as private;
		reset as private;
	}


	/**
	 * WDFix constructor.
	 * @param PluginLoader $loader
	 * @param Server $server
	 * @param PluginDescription $description
	 * @param string $dataFolder
	 * @param string $file
	 * @param ResourceProvider $resourceProvider
	 */
	public function __construct(PluginLoader $loader, Server $server, PluginDescription $description, string $dataFolder, string $file, ResourceProvider $resourceProvider){
		parent::__construct($loader, $server, $description, $dataFolder, $file, $resourceProvider);
		self::setInstance($this);
	}

	/**
	 * Function checkForUpdate
	 * @param bool $development
	 * @return void
	 */
	private function checkForUpdate(bool $development = false): void{
		$this->getServer()->getAsyncPool()->submitTask(new class($this->getDescription()->getVersion(), $development) extends AsyncTask{
			public function __construct(protected string $version, protected bool $development) {}
			public function onRun(): void{
				$result = Internet::getURL("https://raw.githubusercontent.com/xxAROX/WaterdogPE-LoginExtras-Fix/" . ($this->development ? "development" : "main") . "/plugin.yml");
				if (is_null($result)) return; // NOTE: no internet connection
				if ($result->getCode() !== 200) return;
				try {
					$pluginYml = yaml_parse($result->getBody());
				} catch (Throwable $e) {
					return;
				}
				if (!$pluginYml) return;
				$this->setResult($pluginYml["version"] ?? null);
			}
			public function onCompletion(): void{
				$newVersion = $this->getResult();
				if ($newVersion === null) return;
				if (version_compare($newVersion, $this->version, ">")) {
					WDFix::getInstance()->getLogger()->notice("§eA new version of §6WaterdogPE-LoginExtras-Fix§e is available!");
					WDFix::getInstance()->getLogger()->notice("§eYou can download it from §6https://github.com/xxAROX/WaterdogPE-LoginExtras-Fix/releases/tag/" . ($this->development ? "dev" : "v{$newVersion}"));
				}
			}
		});
	}

	/**
	 * Function onLoad
	 * @return void
	 */
	protected function onLoad(): void{
		$this->saveResource("config.yml");
		self::$KICK_PLAYERS = (boolean)$this->getConfig()->get("kick-players-if-no-waterdog-information-was-found", true);
		self::$KICK_MESSAGE = $this->getConfig()->get("kick-message", "§c{PREFIX}§e: §cNot authenticated to §bWaterdog§3PE§c!§f\n§cPlease connect to §3Waterdog§c!");
		$this->checkForUpdate(str_ends_with($this->getDescription()->getVersion(), "-dev"));
	}

	/**
	 * Function onEnable
	 * @return void
	 */
	protected function onEnable(): void{
		$needServerRestart = false;
		if ($this->getServer()->getConfigGroup()->getPropertyBool("player.verify-xuid", true)) {
			$this->getLogger()->warning("§eMay {$this->getDescription()->getPrefix()} dosn't work correctly fo prevent bugs set §f'§2player.verify-xuid§f' §ein §6pocketmine.yml §eto §f'§cfalse§f'");
			$needServerRestart = true;
		}
		if ($this->getServer()->getOnlineMode()) {
			$this->getLogger()->alert($this->getDescription()->getPrefix() . " is not compatible with online mode!");
			$this->getLogger()->warning("§ePlease set §f'§2xbox-auth§f' §ein §6server.properties §eto §f'§coff§f'");
			$needServerRestart = true;
		}
		if ($needServerRestart) $this->getLogger()->warning("Then restart the server!");
		else {
			$this->getServer()->getPluginManager()->registerEvents($this, $this);
			if (self::$KICK_PLAYERS) {
				$this->getLogger()->alert("§cPlayers §nwill be kicked§r§c if they are not authenticated to §bWaterdog§3PE§c!§r");
			} else {
				$this->getLogger()->info("§aPlayers will §nnot§r§a be kicked if they are not authenticated to §bWaterdog§3PE§a!§r");
			}
		}
	}

	/**
	 * Function DataPacketReceiveEvent
	 * @param DataPacketReceiveEvent $event
	 * @return void
	 * @throws ReflectionException
	 * @priority MONITOR
	 * @handleCancelled true
	 */
	public function DataPacketReceiveEvent(DataPacketReceiveEvent $event): void{
		$packet = $event->getPacket();
		if ($packet instanceof LoginPacket) {
			try {
				[, $clientData,] = JwtUtils::parse($packet->clientDataJwt);
			} catch (JwtException $e) {
				throw PacketHandlingException::wrap($e);
			}
			if (
				(!isset($clientData["Waterdog_XUID"]) || !isset($clientData["Waterdog_IP"]))
				&& $this->getConfig()->get("kick-players-if-no-waterdog-information-was-found", false)
			) {
				$event->getOrigin()->disconnect(str_replace("{PREFIX}", $this->getDescription()->getPrefix(), self::$KICK_MESSAGE));
				return;
			}
			if (isset($clientData["Waterdog_XUID"])) {
				$event->getOrigin()->setHandler(new WaterdogExtrasLoginPacketHandler(
					Server::getInstance(),
					$event->getOrigin(),
					$clientData["Waterdog_XUID"]
				));
			}
			if (isset($clientData["Waterdog_IP"])) {
				$class = new ReflectionClass($event->getOrigin());
				$property = $class->getProperty("ip");
				$property->setAccessible(true);
				$property->setValue($event->getOrigin(), $clientData["Waterdog_IP"]);
			}
			unset($clientData);
		}
	}
}
