<?php
/**
 ************************************************************************
 * Copyright (c) 2005-2020 Stefan (Mütze) Horst                        *
 ************************************************************************
 * I don't have the time to read through all the licences to find out   *
 * what they exactly say. But it's simple. It's free for non-commercial *
 * projects, but as soon as you make money with it, i want my share :-) *
 * (License : Free for non-commercial use)                              *
 ************************************************************************
 * Authors: Stefan (Mütze) Horst <muetze@scoutnet.de>               *
 ************************************************************************
 */

namespace ScoutNet\TestingTools\Mocks;

use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;

class DataMapperMock extends DataMapper
{
    /** @noinspection PhpMissingParentConstructorInspection */
    /**
     * ignore parent Constructor
     */
    public function __construct() {}
}
