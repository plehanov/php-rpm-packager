<?php

use Plehanov\RPM\Packager;
use Plehanov\RPM\Spec;

class PackagerTest extends PHPUnit\Framework\TestCase
{
    public function setUp()
    {
        if (is_dir('/tmp/rpm-test/package')) {
            $this->removeDir('/tmp/rpm-test/package');
        }
        mkdir('/tmp/rpm-test/package', 0755, true);
        if (is_dir('/tmp/rpm-test/output')) {
            $this->removeDir('/tmp/rpm-test/output');
        }
        mkdir('/tmp/rpm-test/output', 0755, true);
        if (is_dir('/tmp/rpm-test/build')) {
            $this->removeDir('/tmp/rpm-test/build');
        }
        mkdir('/tmp/rpm-test/build', 0755, true);
    }

    public function tearDown()
    {
        $this->removeDir('/tmp/rpm-test');
    }

    public function testComplex()
    {
        exec('command -v rpm', $output, $result);
        if (empty($output)) {
            $this->markTestSkipped('This test can not be performed on a system without rpm');
        }

        mkdir('/tmp/rpm-test/package/test', 0755, true);
        touch('/tmp/rpm-test/package/test/binary');
        chmod('/tmp/rpm-test/package/test/binary', 0755);
        mkdir('/tmp/rpm-test/package/test2');
        touch('/tmp/rpm-test/package/test2/abc');

        $spec = new Spec();
        $spec
            ->setProp('Name', 'test-c')
            ->setProp('Release', 2)
            ->setBlock('prep', '%autosetup -c package')
            ->appendInstallCommand('echo %{destroot}');
        $packager = new Packager();
        $packager
            ->setSpec($spec)
            ->setOutputPath('/tmp/rpm-test/output')
            ->addMount('/tmp/rpm-test/package/test/binary', '%{destroot}/binary')
            ->addMount('/tmp/rpm-test/package/test2/', '/tmp/test/lib/')
            ->setDestinationFolder('/tmp/test/');

        $packager->run();

        $this->assertEquals('%autosetup -c package', $spec->prep);
        $this->assertEquals(['rm -rf %{buildroot}', 'mkdir -p %{buildroot}', 'cp -rp * %{buildroot}', 'echo %{destroot}'], $spec->install);
        $this->assertEquals("/tmp/test/binary\n/tmp/test/lib\n", $spec->files);
        $this->assertFileExists($packager->getBuildPath() . '/rpmbuild/SPECS/test-c.spec');
        $this->assertFileExists($packager->getBuildPath() . '/rpmbuild/SOURCES/test-c.tar');

        $phar = new PharData($packager->getBuildPath() . '/rpmbuild/SOURCES/test-c.tar');
        $this->assertArrayHasKey('tmp/test/binary', $phar);
        $this->assertArrayHasKey('tmp/test/lib/abc', $phar);

        $command = $packager->build();
        $this->assertEquals('rpmbuild -bb ' . $packager->getBuildPath() . '/rpmbuild/SPECS/test-c.spec', $command);
        exec($command, $_, $result);

        $this->assertEquals(0, $result);
        $this->assertFileExists($packager->getBuildPath() . '/rpmbuild/RPMS/noarch/test-c-0.1-2.noarch.rpm');
        $this->assertTrue($packager->movePackage('/tmp/rpm-test/test-c-0.1.rpm'));
        $this->assertFileExists('/tmp/rpm-test/test-c-0.1.rpm');

        $expectedFiles = ['/tmp/test/binary', '/tmp/test/lib', '/tmp/test/lib/abc'];
        exec('rpm -qlp ' . '/tmp/rpm-test/test-c-0.1.rpm', $actualFiles, $_);
        $this->assertEquals($expectedFiles, $actualFiles);

        unlink('/tmp/rpm-test/test-c-0.1.rpm');
        unlink($packager->getBuildPath() . '/rpmbuild/SPECS/test-c.spec');
        unlink($packager->getBuildPath() . '/rpmbuild/SOURCES/test-c.tar');
    }

    public function testSimple()
    {
        exec('command -v rpm', $output, $result);
        if (empty($output)) {
            $this->markTestSkipped('This test can not be performed on a system without rpm');
        }

        mkdir('/tmp/rpm-test/package/test', 0755, true);
        touch('/tmp/rpm-test/package/test/binary');
        chmod('/tmp/rpm-test/package/test/binary', 0755);
        mkdir('/tmp/rpm-test/package/test2');
        touch('/tmp/rpm-test/package/test2/abc');

        $spec = new Spec();
        $spec
            ->setBlock('prep', '%autosetup -c package')
            ->setProp('Name', 'test-s');
        $packager = new Packager();
        $packager->setSpec($spec);
        $packager->setOutputPath('/tmp/rpm-test/output');
        $packager->addMount('/tmp/rpm-test/package/', '/usr/share/test/');
        $packager->run();

        $this->assertEquals('%autosetup -c package', $spec->prep);
        $this->assertEquals(['rm -rf %{buildroot}', 'mkdir -p %{buildroot}', 'cp -rp * %{buildroot}'], $spec->install);
        $this->assertEquals("/usr/share/test\n", $spec->files);
        $this->assertFileExists($packager->getBuildPath() . '/rpmbuild/SPECS/test-s.spec');
        $this->assertFileExists($packager->getBuildPath() . '/rpmbuild/SOURCES/test-s.tar');

        $phar = new PharData($packager->getBuildPath() . '/rpmbuild/SOURCES/test-s.tar');
        $this->assertArrayHasKey('usr/share/test/test/binary', $phar);
        $this->assertArrayHasKey('usr/share/test/test2/abc', $phar);

        $command = $packager->build();
        $this->assertEquals('rpmbuild -bb ' . $packager->getBuildPath() . '/rpmbuild/SPECS/test-s.spec', $command);
        exec($command, $_, $result);

        $this->assertEquals(0, $result);
        $this->assertFileExists($packager->getBuildPath() . '/rpmbuild/RPMS/noarch/test-s-0.1-1.noarch.rpm');
        $this->assertTrue($packager->movePackage('/tmp/rpm-test/test-s-0.1.rpm'));
        $this->assertFileExists('/tmp/rpm-test/test-s-0.1.rpm');

        $expectedFiles = [
            '/usr/share/test',
            '/usr/share/test/test',
            '/usr/share/test/test/binary',
            '/usr/share/test/test2',
            '/usr/share/test/test2/abc'
        ];
        exec('rpm -qlp ' . '/tmp/rpm-test/test-s-0.1.rpm', $actualFiles, $_);
        $this->assertEquals($expectedFiles, $actualFiles);

        unlink('/tmp/rpm-test/test-s-0.1.rpm');
        unlink($packager->getBuildPath() . '/rpmbuild/SPECS/test-s.spec');
        unlink($packager->getBuildPath() . '/rpmbuild/SOURCES/test-s.tar');
    }

    private function removeDir($dir)
    {
        $dd = opendir($dir);
        while (($file = readdir($dd)) !== false) {
            if (in_array($file, array('.', '..'), true)) {
                continue;
            }
            if (is_dir($dir . '/' . $file)) {
                $this->removeDir($dir . '/' . $file);
            } else {
                unlink($dir . '/' . $file);
            }
        }
        closedir($dd);
        rmdir($dir);
    }
}