<?php
/**
 * This file is part of CTX Parser
 */

namespace CTXParser;

/**
 * Main class to enter parsing process
 */
class Parser
{
    /**
     * Tokenizer
     *
     * @var mixed
     */
    protected $tokenizer;

    public function __construct(
        Tokenizer $tokenizer = null
    ) {
        $this->tokenizer = $tokenizer ?: new Tokenizer\Regexp();
    }

    /**
     * Parse the given file
     *
     * @param string $file
     * @return AccountInfoList
     */
    public function parse($file)
    {
        return $this->parseString(file_get_contents($file));
    }

    /**
     * Parse the given string
     *
     * @param string $string
     * @return AccountInfoList
     */
    public function parseString($string)
    {
        $tokens = new Stack($this->tokenizer->tokenize($string));

        if ($tokens[0]->type === Tokenizer::T_EOF) {
            return new CTX\AccountInfoList();
        }

        $ctx = new CTX();
        $this->reduceStruct($tokens, $ctx);

        return $ctx->accountInfoList[0];
    }

    /**
     * Read expected from token array
     *
     * Try to read the given token from the token array. If another token is
     * found, a parse error is issued. If the token is found, the token is
     * removed fromt he token array and returned.
     *
     * @param array $types
     * @param Token[] $tokens
     * @return Token
     */
    private function read(array $types, Stack $tokens)
    {
        $token = $tokens->shift();

        if (!in_array($token->type, $types, true)) {
            $names = array();
            foreach ($types as $type) {
                $names[] = $this->tokenizer->getTokenName($type);
            }

            throw new \RuntimeException(
                "Expected one of: " . implode(', ', $names) . ", found " .
                $this->tokenizer->getTokenName($token->type) .
                ". in line {$token->line} at position {$token->position}."
            );
        }

        return $token;
    }

    /**
     * Reduce struct
     *
     * @param Stack $tokens
     * @param Struct $parent
     * @return void
     */
    protected function reduceStruct(Stack $tokens, Struct $parent)
    {
        $start = $this->read(array(Tokenizer::T_STRUCT_START), $tokens);

        $name = $start->match['name'];
        $structClassName = get_class($parent) . '\\' . ucfirst($name);
        $struct = new $structClassName();

        while (true) {
            switch ($tokens[0]->type) {
                case Tokenizer::T_STRUCT_START:
                    $this->reduceStruct($tokens, $struct);
                    continue 2;
                case Tokenizer::T_VALUE:
                    $this->reduceValue($tokens, $struct);
                    continue 2;
                case Tokenizer::T_ARRAY_VALUE:
                    $this->reduceArrayValue($tokens, $struct);
                    continue 2;
                default:
                    $this->read(array(Tokenizer::T_STRUCT_END), $tokens);
                    break 2;
            }
        }

        if (!is_array($parent->$name)) {
            $parent->$name = array($struct);
        } else {
            $parent->$name = array_merge($parent->$name, array($struct));
        }
    }

    /**
     * Reduce value
     *
     * @param Stack $tokens
     * @param Struct $parent
     * @return void
     */
    protected function reduceValue(Stack $tokens, Struct $parent)
    {
        $token = $this->read(array(Tokenizer::T_VALUE), $tokens);

        $name = $token->match['name'];
        $parent->$name = $this->getValue($token);
    }

    /**
     * Reduce array value
     *
     * @param Stack $tokens
     * @param Struct $parent
     * @return void
     */
    protected function reduceArrayValue(Stack $tokens, Struct $parent)
    {
        $token = $this->read(array(Tokenizer::T_ARRAY_VALUE), $tokens);

        $name = $token->match['name'];
        $parent->$name = array_map(
            function ($value) use ($token) {
                return $this->getValue($token, $value);
            },
            preg_split('(",\\s*")', $token->match['value'])
        );
    }

    /**
     * Convert token value type
     *
     * @param Token $token
     * @param string $value
     * @return mixed
     */
    protected function getValue(Token $token, $value = null)
    {
        $value = $value ?: $token->match['value'];
        $type  = $token->match['type'];

        switch (true) {
            case $type === 'int':
                return (int) $value;
            case $type === 'char' &&
                 preg_match('(^(?P<value>-?\\d+)%2F100$)', $value, $match):
                return $match['value'] / 100;
            case $type === 'char' &&
                preg_match('(^(?P<value>-?\\d+)%2F10$)', $value, $match):
                return $match['value'] / 10;
            case $type === 'char':
                return (string) urldecode($value);
            default:
                throw new \RuntimeException(
                    "Unknown value type $type in line {$token->line} at position {$token->position}."
                );
        }
    }
}
