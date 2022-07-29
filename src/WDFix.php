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
use JsonMapper_Exception;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
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
use Throwable;


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
	 * Function onLoad
	 * @return void
	 */
	protected function onLoad(): void{
		$this->saveResource("config.yml");
		self::$KICK_PLAYERS = $this->getConfig()->get("kick-players-if-no-waterdog-information-was-found", true);
		self::$KICK_MESSAGE = $this->getConfig()->get("kick-message", "§c{PREFIX}§e: §cNot authenticated to §3Waterdog§c!§f\n§cPlease connect to §3Waterdog§c!");
		$this->checkForUpdate();
	}

	/**
	 * Function onEnable
	 * @return void
	 */
	protected function onEnable(): void{
		if (Server::getInstance()->getOnlineMode()) {
			$this->getLogger()->alert( $this->getDescription()->getPrefix() . " is not compatible with online mode!");
			$this->getLogger()->warning("§ePlease set §f'§2xbox-auth§f' §ein §6server.properties §eto §f'§coff§f'");
			$this->getLogger()->warning("Then restart the server!");
		} else {
			$this->getServer()->getPluginManager()->registerEvents($this, $this);
			if (self::$KICK_PLAYERS) {
				$this->getLogger()->alert("§cPlayers will be kicked if they are not authenticated to §3Waterdog§c!§r");
			} else {
				$this->getLogger()->alert("§aPlayers will §nnot§r§a be kicked if they are not authenticated to §3Waterdog§a!§r");
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
			$event->getOrigin()->setHandler(
				new class(Server::getInstance(), $event->getOrigin(), function (XboxLivePlayerInfo $info) use ($event, $clientData, $packet): void{
				$class = new ReflectionClass($event->getOrigin());
				$property = $class->getProperty("info");
				$property->setAccessible(true);
				$property->setValue($event->getOrigin(), new XboxLivePlayerInfo($clientData["Waterdog_XUID"], $info->getUsername(), $info->getUuid(), $info->getSkin(), $info->getLocale(), $info->getExtraData()));
			}, function (bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) use ($event): void{
				$class = new ReflectionClass($event->getOrigin());
				$method = $class->getMethod("setAuthenticationStatus");
				$method->setAccessible(true);
				$method->invoke($event->getOrigin(), $isAuthenticated, $authRequired, $error, $clientPubKey);
			}) extends LoginPacketHandler{
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
					$mapper = new \JsonMapper;
					$mapper->bEnforceMapType = false;
					$mapper->bExceptionOnMissingData = true;
					$mapper->bExceptionOnUndefinedProperty = true;
					try {
						$properties = array_map(fn(\ReflectionProperty $property) => $property->getName(), (new ReflectionClass(ClientData::class))->getProperties());
						foreach ($clientDataClaims as $k => $v) {
							if (!in_array($k, $properties)) {
								unset($clientDataClaims[$k]);
							}
						}
						unset($properties);
						$clientData = $mapper->map($clientDataClaims, new ClientData);
					} catch (JsonMapper_Exception $e) {
						throw PacketHandlingException::wrap($e);
					}
					return $clientData;
				}
			}
			);
			if (isset($clientData["Waterdog_IP"])) {
				$class = new ReflectionClass($event->getOrigin());
				$property = $class->getProperty("ip");
				$property->setAccessible(true);
				$property->setValue($event->getOrigin(), $clientData["Waterdog_IP"]);
			}
			if (isset($clientData["Waterdog_XUID"])) {
				$class = new ReflectionClass($event->getOrigin());
				$property = $class->getProperty("xuid");
				$property->setAccessible(true);
				$property->setValue($event->getOrigin(), $clientData["Waterdog_XUID"]);
			}
			unset($clientData);
		}
	}

	/**
	 * Function checkForUpdate
	 * @return void
	 */
	private function checkForUpdate(): void{
		$this->getServer()->getAsyncPool()->submitTask(new class($this->getDescription()->getVersion()) extends AsyncTask{
			public function __construct(protected string $version) {}
			public function onRun(): void{
				$result = Internet::getURL("https://raw.githubusercontent.com/xxAROX/WaterdogPE-LoginExtras-Fix/main/plugin.yml");
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
					WDFix::getInstance()->getLogger()->notice("§eYou can download it from §6https://github.com/xxAROX/WaterdogPE-LoginExtras-Fix/releases/tag/latest");
				}
			}
		});
	}
}
