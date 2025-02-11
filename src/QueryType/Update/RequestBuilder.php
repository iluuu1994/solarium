<?php

namespace Solarium\QueryType\Update;

use Solarium\Core\Client\Request;
use Solarium\Core\Query\AbstractQuery;
use Solarium\Core\Query\AbstractRequestBuilder as BaseRequestBuilder;
use Solarium\Core\Query\QueryInterface;
use Solarium\Exception\RuntimeException;
use Solarium\QueryType\Update\Query\Command\Add;
use Solarium\QueryType\Update\Query\Command\Commit;
use Solarium\QueryType\Update\Query\Command\Delete;
use Solarium\QueryType\Update\Query\Command\Optimize;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;

/**
 * Build an update request.
 */
class RequestBuilder extends BaseRequestBuilder
{
    /**
     * Build request for an update query.
     *
     * @param QueryInterface|AbstractQuery|UpdateQuery $query
     *
     * @return Request
     */
    public function build(AbstractQuery $query): Request
    {
        $request = parent::build($query);
        $request->setMethod(Request::METHOD_POST);
        $request->setRawData($this->getRawData($query));

        return $request;
    }

    /**
     * Generates raw POST data.
     *
     * Each commandtype is delegated to a separate builder method.
     *
     * @param UpdateQuery $query
     *
     * @throws RuntimeException
     *
     * @return string
     */
    public function getRawData(UpdateQuery $query): string
    {
        $xml = '<update>';
        foreach ($query->getCommands() as $command) {
            switch ($command->getType()) {
                case UpdateQuery::COMMAND_ADD:
                    $xml .= $this->buildAddXml($command);
                    break;
                case UpdateQuery::COMMAND_DELETE:
                    $xml .= $this->buildDeleteXml($command);
                    break;
                case UpdateQuery::COMMAND_OPTIMIZE:
                    $xml .= $this->buildOptimizeXml($command);
                    break;
                case UpdateQuery::COMMAND_COMMIT:
                    $xml .= $this->buildCommitXml($command);
                    break;
                case UpdateQuery::COMMAND_ROLLBACK:
                    $xml .= $this->buildRollbackXml();
                    break;
                default:
                    throw new RuntimeException('Unsupported command type');
                    break;
            }
        }
        $xml .= '</update>';

        return $xml;
    }

    /**
     * Build XML for an add command.
     *
     * @param Add $command
     *
     * @return string
     */
    public function buildAddXml(Add $command): string
    {
        $xml = '<add';
        $xml .= $this->boolAttrib('overwrite', $command->getOverwrite());
        $xml .= $this->attrib('commitWithin', $command->getCommitWithin());
        $xml .= '>';

        foreach ($command->getDocuments() as $doc) {
            $xml .= '<doc';
            $xml .= $this->attrib('boost', $doc->getBoost());
            $xml .= '>';

            foreach ($doc->getFields() as $name => $value) {
                $boost = $doc->getFieldBoost($name);
                $modifier = $doc->getFieldModifier($name);
                $xml .= $this->buildFieldsXml($name, $boost, $value, $modifier);
            }

            $version = $doc->getVersion();
            if (null !== $version) {
                $xml .= $this->buildFieldXml('_version_', null, $version);
            }

            $xml .= '</doc>';
        }

        $xml .= '</add>';

        return $xml;
    }

    /**
     * Build XML for a delete command.
     *
     * @param Delete $command
     *
     * @return string
     */
    public function buildDeleteXml(Delete $command): string
    {
        $xml = '<delete>';
        foreach ($command->getIds() as $id) {
            $xml .= '<id>'.htmlspecialchars($id, ENT_NOQUOTES).'</id>';
        }
        foreach ($command->getQueries() as $query) {
            $xml .= '<query>'.htmlspecialchars($query, ENT_NOQUOTES).'</query>';
        }
        $xml .= '</delete>';

        return $xml;
    }

    /**
     * Build XML for an update command.
     *
     * @param Optimize $command
     *
     * @return string
     */
    public function buildOptimizeXml(Optimize $command): string
    {
        $xml = '<optimize';
        $xml .= $this->boolAttrib('softCommit', $command->getSoftCommit());
        $xml .= $this->boolAttrib('waitSearcher', $command->getWaitSearcher());
        $xml .= $this->attrib('maxSegments', $command->getMaxSegments());
        $xml .= '/>';

        return $xml;
    }

    /**
     * Build XML for a commit command.
     *
     * @param Commit $command
     *
     * @return string
     */
    public function buildCommitXml(Commit $command): string
    {
        $xml = '<commit';
        $xml .= $this->boolAttrib('softCommit', $command->getSoftCommit());
        $xml .= $this->boolAttrib('waitSearcher', $command->getWaitSearcher());
        $xml .= $this->boolAttrib('expungeDeletes', $command->getExpungeDeletes());
        $xml .= '/>';

        return $xml;
    }

    /**
     * Build XMl for a rollback command.
     *
     * @return string
     */
    public function buildRollbackXml(): string
    {
        return '<rollback/>';
    }

    /**
     * Build XML for a field.
     *
     * Used in the add command
     *
     * @param string      $name
     * @param float|null  $boost
     * @param mixed       $value
     * @param string|null $modifier
     *
     * @return string
     */
    protected function buildFieldXml(string $name, ?float $boost, $value, ?string $modifier = null): string
    {
        $xml = '<field name="'.$name.'"';
        $xml .= $this->attrib('boost', $boost);
        $xml .= $this->attrib('update', $modifier);
        if (null === $value) {
            $xml .= $this->attrib('null', 'true');
        } elseif (false === $value) {
            $value = 'false';
        } elseif (true === $value) {
            $value = 'true';
        } elseif ($value instanceof \DateTimeInterface) {
            $value = $this->getHelper()->formatDate($value);
        } else {
            $value = htmlspecialchars($value, ENT_NOQUOTES);
        }

        $xml .= '>'.$value.'</field>';

        return $xml;
    }

    /**
     * @param string      $key
     * @param float|null  $boost
     * @param mixed       $value
     * @param string|null $modifier
     *
     * @return string
     */
    private function buildFieldsXml(string $key, ?float $boost, $value, ?string $modifier): string
    {
        $xml = '';
        if (is_array($value)) {
            foreach ($value as $multival) {
                if (is_array($multival)) {
                    $xml .= '<doc>';
                    foreach ($multival as $k => $v) {
                        $xml .= $this->buildFieldsXml($k, $boost, $v, $modifier);
                    }
                    $xml .= '</doc>';
                } else {
                    $xml .= $this->buildFieldXml($key, $boost, $multival, $modifier);
                }
            }
        } else {
            $xml .= $this->buildFieldXml($key, $boost, $value, $modifier);
        }

        return $xml;
    }
}
