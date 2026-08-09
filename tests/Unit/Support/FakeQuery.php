<?php

namespace EcAuthLogin2\Tests\Unit\Support;

/**
 * SC_Query_Ex の最小フェイク。
 *
 * SC_Query_Ex::getSingletonInstance() は実 DB 接続を張るためユニットテストから
 * 呼べない。clearMemberEcauthSubjects() が使う count() / update() だけを実装し、
 * 呼び出し引数を記録して検証できるようにする。
 *
 * count() が文字列を返すのは実物に合わせるため。MDB2 経由の COUNT(*) は
 * 文字列で返るので、呼び出し側の (int) キャストが効いていることも検証できる。
 */
class FakeQuery
{
    /** @var string COUNT(*) の戻り値 */
    private $countResult;

    /** @var array<int, array<string, mixed>> count() の呼び出し履歴 */
    public $countCalls = array();

    /** @var array<int, array<string, mixed>> update() の呼び出し履歴 */
    public $updateCalls = array();

    /**
     * @param int|string $countResult
     */
    public function __construct($countResult)
    {
        $this->countResult = (string) $countResult;
    }

    /**
     * @param string $table
     * @param string $where
     * @param array $arrWhereVal
     * @return string
     */
    public function count($table, $where = '', $arrWhereVal = array())
    {
        $this->countCalls[] = array(
            'table' => $table,
            'where' => $where,
            'whereValues' => $arrWhereVal,
        );

        return $this->countResult;
    }

    /**
     * @param string $table
     * @param array $arrVal
     * @param string $where
     * @param array $arrWhereVal
     * @param array $arrRawSql
     * @param array $arrRawSqlVal
     * @return bool
     */
    public function update($table, $arrVal, $where = '', $arrWhereVal = array(), $arrRawSql = array(), $arrRawSqlVal = array())
    {
        $this->updateCalls[] = array(
            'table' => $table,
            'values' => $arrVal,
            'where' => $where,
            'whereValues' => $arrWhereVal,
            'rawSql' => $arrRawSql,
            'rawSqlValues' => $arrRawSqlVal,
        );

        return true;
    }
}
