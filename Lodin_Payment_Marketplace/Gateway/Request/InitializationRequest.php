<?php
namespace Lodin\Payment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;

class InitializationRequest implements BuilderInterface
{
    public function build(array $buildSubject)
    {
        return ['IGNORED' => true];
    }
}
