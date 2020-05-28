import { check, group } from "k6";
import http from "k6/http";
import { Rate } from "k6/metrics";

export let errorRate = new Rate("errors");

export let options = {
    maxRedirects: 0,
    thresholds: {
        "errors": ["rate==0"],
    }
};

export default function() {
    group("notFound", function() {
        let res = http.get(`http://${__ENV.IMAGE_IP}:6969/`);
        let result = check(res, {
            "is status 200": (r) => r.status === 404,
        });
        errorRate.add(!result);
    });
    group("metrics", function() {
        let res = http.get(`http://${__ENV.IMAGE_IP}:9696/`);
        let result = check(res, {
            "is status 200": (r) => r.status === 200,
        });
        errorRate.add(!result);
    });
};
