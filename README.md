# Club da Venda em PHP

Versão PHP separada do projeto Next.js. Ela será ligada ao mesmo PostgreSQL/Supabase, sem alterar o sistema atual.

## Estrutura inicial

- `public/`: ponto de entrada e arquivos públicos.
- `src/`: regras, conexão com banco, autenticação e telas.
- `storage/`: logs locais que não devem ser publicados.

## Ambiente necessário

- PHP 8.2 ou superior com extensões `pdo_pgsql`, `mbstring` e `openssl`.
- Composer (será usado na etapa de dependências).
- Acesso seguro ao banco PostgreSQL/Supabase por variáveis de ambiente.

Enquanto esta versão está em desenvolvimento, o projeto atual continua sendo o sistema em produção.

## Desenvolvimento local

Enquanto o arquivo `.env` próprio não existir, a versão PHP usa somente a
`DATABASE_URL` já configurada no projeto atual para teste local. Antes da
hospedagem, crie um `.env` nesta pasta com credenciais próprias e remova essa
ponte de desenvolvimento.
