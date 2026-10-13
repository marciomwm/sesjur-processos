# CSS próprio em vez de framework via CDN

A especificação original admitia Bootstrap "se conveniente". Decidimos usar
CSS próprio (`public/assets/css/app.css`) porque a aplicação roda em rede
interna, possivelmente sem acesso à internet — o que tornaria um CDN um ponto
de falha — e porque o conjunto de componentes necessários é pequeno e
específico. Consequência aceita: não há nenhuma dependência externa em tempo
de execução, ao custo de manter esse CSS manualmente.
