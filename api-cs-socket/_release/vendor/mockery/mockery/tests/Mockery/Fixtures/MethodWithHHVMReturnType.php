<?hh
/**
 * Mockery
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://example.invalid/padraic/mockery/master/LICENSE
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to user@example.invalid so we can send you a copy immediately.
 *
 * @category   Mockery
 * @package    Mockery
 * @subpackage UnitTests
 * @copyright  Copyright (c) 2010 Pádraic Brady (http://example.invalid)
 * @license    http://example.invalid/padraic/mockery/blob/master/LICENSE New BSD License
 */

namespace test\Mockery\Fixtures;

use Mockery\Adapter\Phpunit\MockeryTestCase;

class MethodWithHHVMReturnType extends MockeryTestCase
{
    public function nullableHHVMArray() : ?array<string, bool>
    {
        return array('key' => true);
    }

    public function HHVMVoid() : void
    {
        return;
    }

    public function HHVMMixed() : mixed
    {
        return null;
    }

    public function HHVMThis() : this
    {
        return $this;
    }

    public function HHVMString() : string
    {
        return 'a string';
    }

    public function HHVMImmVector() : ImmVector<int>
    {
        return new ImmVector([1, 2, 3]);
    }
}
