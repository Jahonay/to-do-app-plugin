
const WP_API_URL = 'http://localhost:10024/wp-json/wp/v2';

const getCurrentUser = async () => {
    /*const user = await fetch(`${WP_API_URL}/users/me`, {*/
    const user = await fetch(`http://localhost:10024/wp-json/wp/v2/users/me`, {

        method: 'GET',
        headers: {
            'Authorization': `Basic amFob25heTo3U25aIHdoWTEgSTlMQSBTN0NXIFMxTTQgcHlTeA==`,
        },
    });
    return user.json();
}

var user = getCurrentUser();
console.log(user);