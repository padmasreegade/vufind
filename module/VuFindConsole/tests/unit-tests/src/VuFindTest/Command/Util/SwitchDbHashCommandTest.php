<?php

/**
 * SwitchDbHashCommand test.
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2020.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Tests
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */

namespace VuFindTest\Command\Util;

use Laminas\Config\Config;
use Laminas\Crypt\BlockCipher;
use Laminas\Crypt\Symmetric\Openssl;
use Symfony\Component\Console\Tester\CommandTester;
use VuFind\Config\Writer;
use VuFind\Db\Entity\UserCard;
use VuFind\Db\Row\User as UserRow;
use VuFind\Db\Service\UserCardService;
use VuFind\Db\Table\User;
use VuFindConsole\Command\Util\SwitchDbHashCommand;

/**
 * SwitchDbHashCommand test.
 *
 * @category VuFind
 * @package  Tests
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
class SwitchDbHashCommandTest extends \PHPUnit\Framework\TestCase
{
    use \VuFindTest\Feature\PathResolverTrait;
    use \VuFindTest\Feature\WithConsecutiveTrait;

    /**
     * Expected path to config.ini
     *
     * @var string
     */
    protected $expectedConfigIniPath;

    /**
     * Encryption algorithm to use
     *
     * @var string
     */
    protected $encryptionAlgorithm = 'aes';

    /**
     * Get mock user table object
     *
     * @return User
     */
    protected function getMockUserTable(): User
    {
        return $this->createMock(User::class);
    }

    /**
     * Get mock card table object
     *
     * @return UserCardService
     */
    protected function getMockCardService(): UserCardService
    {
        return $this->createMock(UserCardService::class);
    }

    /**
     * Get mock command object
     *
     * @param array            $config      Config settings
     * @param ?User            $userTable   User table gateway
     * @param ?UserCardService $cardService User table gateway
     *
     * @return SwitchDbhashCommand
     */
    protected function getMockCommand(array $config = [], ?User $userTable = null, ?UserCardService $cardService = null)
    {
        return $this->getMockBuilder(SwitchDbHashCommand::class)
            ->setConstructorArgs(
                [
                    new Config($config),
                    $userTable ?? $this->getMockUserTable(),
                    $cardService ?? $this->getMockCardService(),
                ]
            )->onlyMethods(['getConfigWriter'])
            ->getMock();
    }

    /**
     * Get a mock config writer
     *
     * @return Writer
     */
    protected function getMockConfigWriter(): Writer
    {
        return $this->createMock(Writer::class);
    }

    /**
     * Standard setup method.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->expectedConfigIniPath = $this->getPathResolver()
            ->getLocalConfigPath('config.ini', null, true);
    }

    /**
     * Test that missing parameters yield an error message.
     *
     * @return void
     */
    public function testWithoutParameters(): void
    {
        $this->expectException(
            \Symfony\Component\Console\Exception\RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Not enough arguments (missing: "newmethod").'
        );
        $command = $this->getMockCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
    }

    /**
     * Test missing key parameter (not in config or on command line).
     *
     * @return void
     */
    public function testWithoutKeyParameter(): void
    {
        $command = $this->getMockCommand();
        $commandTester = new CommandTester($command);
        $commandTester->execute(['newmethod' => $this->encryptionAlgorithm]);
        $this->assertEquals(1, $commandTester->getStatusCode());
        $this->assertEquals(
            "Please specify a key as the second parameter.\n",
            $commandTester->getDisplay()
        );
    }

    /**
     * Test no action needed because no changes requested.
     *
     * @return void
     */
    public function testNoActionNeeded(): void
    {
        $command = $this->getMockCommand(
            [
                'Authentication' => [
                    'encrypt_ils_password' => true,
                    'ils_encryption_algo' => $this->encryptionAlgorithm,
                    'ils_encryption_key' => 'bar',
                ],
            ]
        );
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['newmethod' => $this->encryptionAlgorithm, 'newkey' => 'bar']
        );
        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(
            "No changes requested -- no action needed.\n",
            $commandTester->getDisplay()
        );
    }

    /**
     * Test failed configuration write.
     *
     * @return void
     */
    public function testFailedConfigWrite(): void
    {
        $writer = $this->getMockConfigWriter();
        $writer->expects($this->once())->method('save')
            ->will($this->returnValue(false));
        $command = $this->getMockCommand();
        $command->expects($this->once())->method('getConfigWriter')
            ->will($this->returnValue($writer));
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['newmethod' => $this->encryptionAlgorithm, 'newkey' => 'foo']
        );
        $this->assertEquals(1, $commandTester->getStatusCode());
        $this->assertEquals(
            "\tUpdating {$this->expectedConfigIniPath}...\n\tWrite failed!\n",
            $commandTester->getDisplay()
        );
    }

    /**
     * Test success with no users to update.
     *
     * @return void
     */
    public function testSuccessNoUsers(): void
    {
        $writer = $this->getMockConfigWriter();
        $this->expectConsecutiveCalls(
            $writer,
            'set',
            [
                ['Authentication', 'encrypt_ils_password', true],
                [
                    'Authentication',
                    'ils_encryption_algo',
                    $this->encryptionAlgorithm,
                ],
                ['Authentication', 'ils_encryption_key', 'foo'],
            ]
        );
        $writer->expects($this->once())->method('save')
            ->will($this->returnValue(true));
        $userTable = $this->getMockUserTable();
        $userTable->expects($this->once())->method('select')
            ->will($this->returnValue([]));
        $cardService = $this->getMockCardService();
        $cardService->expects($this->once())->method('getAllRowsWithUsernames')
            ->will($this->returnValue([]));
        $command = $this->getMockCommand([], $userTable, $cardService);
        $command->expects($this->once())->method('getConfigWriter')
            ->will($this->returnValue($writer));
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['newmethod' => $this->encryptionAlgorithm, 'newkey' => 'foo']
        );
        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(
            "\tUpdating {$this->expectedConfigIniPath}...\n\tConverting hashes for"
            . " 0 user(s).\n\tFinished.\n",
            $commandTester->getDisplay()
        );
    }

    /**
     * Get a mock row representing a user.
     *
     * @return UserRow
     */
    protected function getMockUserObject(): UserRow
    {
        $user = $this->createMock(UserRow::class);
        $user->method('getId')->willReturn(2);
        $user->method('getUsername')->willReturn('foo');
        $user->method('getEmail')->willReturn('fake@myuniversity.edu');
        $user->method('getCreated')->willReturn(\DateTime::createFromFormat('Y-m-d H:i:s', '2000-01-01 00:00:00'));
        $user->method('getRawCatPassword')->willReturn('mypassword');
        $user->method('getLastLanguage')->willReturn('en');
        // Use mock setters and getters to actually store/retrieve an encrypted password value
        $pass = null;
        $setPass = function ($new) use (&$pass) {
            $pass = $new;
            return true;
        };
        $user->method('setCatPassEnc')->with($this->callback($setPass))->willReturn($user);
        $getPass = function () use (&$pass) {
            return $pass;
        };
        $user->method('getCatPassEnc')->willReturnCallback($getPass);
        return $user;
    }

    /**
     * Get a mock row representing a card.
     *
     * @return UserCard
     */
    protected function getUserCardEntity(): UserCard
    {
        $card = new \VuFind\Db\Entity\UserCard();
        $card->setRawCatPassword('mypassword');
        return $card;
    }

    /**
     * Decode a hash to confirm that it was encoded correctly.
     *
     * @param string $hash Hash to decode
     *
     * @return string
     */
    protected function decode(string $hash): string
    {
        $cipher = new BlockCipher(
            new Openssl(['algorithm' => $this->encryptionAlgorithm])
        );
        $cipher->setKey('foo');
        return $cipher->decrypt($hash);
    }

    /**
     * Test success with a user to update.
     *
     * @return void
     */
    public function testSuccessWithUser(): void
    {
        $writer = $this->getMockConfigWriter();
        $this->expectConsecutiveCalls(
            $writer,
            'set',
            [
                ['Authentication', 'encrypt_ils_password', true],
                [
                    'Authentication',
                    'ils_encryption_algo',
                    $this->encryptionAlgorithm,
                ],
                ['Authentication', 'ils_encryption_key', 'foo'],
            ]
        );
        $writer->expects($this->once())->method('save')
            ->will($this->returnValue(true));
        $user = $this->getMockUserObject();
        $user->expects($this->once())->method('save');
        $userTable = $this->getMockUserTable();
        $userTable->expects($this->once())->method('select')
            ->will($this->returnValue([$user]));
        $cardService = $this->getMockCardService();
        $cardService->expects($this->once())->method('getAllRowsWithUsernames')
                ->will($this->returnValue([]));
        $command = $this->getMockCommand([], $userTable, $cardService);
        $command->expects($this->once())->method('getConfigWriter')
            ->will($this->returnValue($writer));
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['newmethod' => $this->encryptionAlgorithm, 'newkey' => 'foo']
        );
        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(
            "\tUpdating {$this->expectedConfigIniPath}...\n\tConverting hashes for"
            . " 1 user(s).\n\tFinished.\n",
            $commandTester->getDisplay()
        );
        $this->assertEquals(null, $user['cat_password']);
        $this->assertEquals('mypassword', $this->decode($user->getCatPassEnc()));
    }

    /**
     * Test success with a card to update.
     *
     * @return void
     */
    public function testSuccessWithCard(): void
    {
        $writer = $this->getMockConfigWriter();
        $this->expectConsecutiveCalls(
            $writer,
            'set',
            [
                ['Authentication', 'encrypt_ils_password', true],
                [
                    'Authentication',
                    'ils_encryption_algo',
                    $this->encryptionAlgorithm,
                ],
                ['Authentication', 'ils_encryption_key', 'foo'],
            ]
        );
        $writer->expects($this->once())->method('save')
            ->will($this->returnValue(true));
        $card = $this->getUserCardEntity();
        $userTable = $this->getMockUserTable();
        $userTable->expects($this->once())->method('select')
            ->will($this->returnValue([]));
        $cardService = $this->getMockCardService();
        $cardService->expects($this->once())->method('getAllRowsWithUsernames')
            ->will($this->returnValue([$card]));
        $cardService->expects($this->once())->method('persistEntity')
            ->with($this->equalTo($card));
        $command = $this->getMockCommand([], $userTable, $cardService);
        $command->expects($this->once())->method('getConfigWriter')
            ->will($this->returnValue($writer));
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['newmethod' => $this->encryptionAlgorithm, 'newkey' => 'foo']
        );
        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(
            "\tUpdating {$this->expectedConfigIniPath}...\n\tConverting hashes for"
            . " 0 user(s).\n\tConverting hashes for 1 card(s).\n\tFinished.\n",
            $commandTester->getDisplay()
        );
        $this->assertEquals(null, $card->getRawCatPassword());
        $this->assertEquals('mypassword', $this->decode($card->getCatPassEnc()));
    }
}
